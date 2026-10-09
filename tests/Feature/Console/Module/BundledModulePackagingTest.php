<?php

namespace Tests\Feature\Console\Module;

use App\Console\Commands\Module\UpdateModuleCommand;
use App\Contracts\Extension\ModuleInterface;
use App\Contracts\Repositories\ModuleRepositoryInterface;
use App\Extension\ExtensionManager;
use App\Extension\Helpers\ExtensionPendingHelper;
use App\Extension\ModuleManager;
use App\Extension\Vendor\VendorBundleInstaller;
use App\Extension\Vendor\VendorIntegrityChecker;
use App\Extension\Vendor\VendorMode;
use App\Models\Module;
use App\Services\LayoutExtensionService;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\File;
use Mockery;
use Monolog\Handler\NullHandler;
use ReflectionProperty;
use RuntimeException;
use Symfony\Component\Console\Tester\CommandTester;
use Tests\TestCase;

class BundledModulePackagingTest extends TestCase
{
    private string $root;

    private string $fixture;

    public function createApplication()
    {
        $app = require dirname(__DIR__, 4).'/bootstrap/app.php';
        $app->make(Kernel::class)->bootstrap();

        return $app;
    }

    protected function setUp(): void
    {
        parent::setUp();
        $this->root = base_path();
        $this->fixture = storage_path('framework/testing/bundled-packaging-'.uniqid());
        File::ensureDirectoryExists($this->fixture);
        config(['logging.default' => 'null', 'logging.channels.null' => ['driver' => 'monolog', 'handler' => NullHandler::class]]);
    }

    protected function tearDown(): void
    {
        $this->app->setBasePath($this->root);
        File::deleteDirectory($this->fixture);
        parent::tearDown();
    }

    private function record(string $version = '1.2.0'): Module
    {
        return new Module(['identifier' => 'sirsoft-ecommerce', 'version' => $version, 'status' => 'active', 'vendor_mode' => 'bundled']);
    }

    public function test_cli_uses_requested_bundle_version_and_never_github_precheck(): void
    {
        $record = $this->record();
        $repository = Mockery::mock(ModuleRepositoryInterface::class);
        $repository->shouldReceive('findByIdentifier')->with('sirsoft-ecommerce')->andReturn($record);
        $manager = Mockery::mock(ModuleManager::class);
        $manager->shouldReceive('loadModules')->once();
        $manager->shouldReceive('getBundledModules')->andReturn(['sirsoft-ecommerce' => ['version' => '1.2.1']]);
        $manager->shouldNotReceive('checkModuleUpdate');
        $manager->shouldReceive('updateModule')->once()->withArgs(function ($id, $force, $progress, $mode, $layout, $upgrade, $source, $zip) {
            return $id === 'sirsoft-ecommerce' && $force && $mode === VendorMode::Bundled && $layout === 'overwrite' && $source === 'bundled' && $zip === null;
        })->andReturn(['success' => true, 'from_version' => '1.2.0', 'to_version' => '1.2.1']);
        $layouts = Mockery::mock(LayoutExtensionService::class);
        $layouts->shouldReceive('getModifiedExtensionsBySource')->andReturn([]);
        $command = new UpdateModuleCommand($manager, $repository, $layouts);
        $command->setLaravel($this->app);
        $tester = new CommandTester($command);
        $this->assertSame(0, $tester->execute(['identifier' => 'sirsoft-ecommerce', '--source' => 'bundled', '--vendor-mode' => 'bundled', '--force' => true], ['interactive' => false]));
        $this->assertStringContainsString('bundled', $tester->getDisplay());
        $this->assertStringContainsString('1.2.1', $tester->getDisplay());
        $this->assertStringNotContainsString('github', $tester->getDisplay());
    }

    public function test_real_update_stages_verified_bundle_and_reaches_file_apply_without_github(): void
    {
        $source = $this->root.'/modules/_bundled/sirsoft-ecommerce';
        $bundled = $this->fixture.'/modules/_bundled/sirsoft-ecommerce';
        $active = $this->fixture.'/modules/sirsoft-ecommerce';
        File::ensureDirectoryExists($bundled);
        File::ensureDirectoryExists($active);
        foreach (['composer.json', 'composer.lock', 'vendor-bundle.json', 'vendor-bundle.zip', 'module.json'] as $file) {
            File::copy($source.'/'.$file, $bundled.'/'.$file);
        }
        File::put($bundled.'/source-marker.txt', 'bundled-1.2.1');
        File::put($active.'/source-marker.txt', 'previous-version');
        $repository = Mockery::mock(ModuleRepositoryInterface::class);
        $repository->shouldReceive('findByIdentifier')->andReturn($this->record());
        $repository->shouldReceive('updateByIdentifier')->andReturn(true);
        $module = Mockery::mock(ModuleInterface::class);
        $manager = Mockery::mock(ModuleManager::class)->makePartial()->shouldAllowMockingProtectedMethods();
        $manager->shouldReceive('getModule')->andReturn($module);
        $manager->shouldReceive('reloadModule')->andReturnNull();
        $manager->shouldNotReceive('checkModuleUpdate');
        $manager->shouldNotReceive('downloadModuleUpdate');
        foreach (['moduleRepository' => $repository, 'extensionManager' => app(ExtensionManager::class), 'modulesPath' => $this->fixture.'/modules', 'bundledModules' => ['sirsoft-ecommerce' => ['version' => '1.2.1']]] as $name => $value) {
            (new ReflectionProperty(ModuleManager::class, $name))->setValue($manager, $value);
        }
        $this->app->setBasePath($this->fixture);
        $reachedApply = false;
        try {
            $manager->updateModule('sirsoft-ecommerce', true, function ($step) use (&$reachedApply) {
                if ($step !== 'files') {
                    return;
                }
                $staging = File::directories($this->fixture.'/modules/_pending');
                $this->assertCount(1, $staging);
                $this->assertSame('bundled-1.2.1', File::get($staging[0].'/source-marker.txt'));
                $this->assertTrue((new VendorIntegrityChecker)->verify($staging[0])->valid);
                $this->assertFileExists($staging[0].'/vendor/autoload.php');
                $this->assertFileExists($staging[0].'/vendor/ezyang/htmlpurifier/library/HTMLPurifier.php');
                $reachedApply = true;
                // Stop before installing files, migrations or database artifacts.
                throw new RuntimeException('packaging-verified-before-apply');
            }, VendorMode::Bundled, sourceOverride: 'bundled');
            $this->fail('Expected controlled stop');
        } catch (RuntimeException $e) {
            $this->assertStringContainsString('packaging-verified-before-apply', $e->getPrevious()?->getMessage() ?? $e->getMessage());
        }
        $this->assertTrue($reachedApply);
        $this->assertSame('previous-version', File::get($active.'/source-marker.txt'));
    }

    public function test_real_bundle_extracts_and_matches_locked_packages_and_root_version(): void
    {
        $source = $this->root.'/modules/_bundled/sirsoft-ecommerce';
        $checker = new VendorIntegrityChecker;
        $this->assertTrue($checker->verify($source)->valid);
        $lock = json_decode(File::get($source.'/composer.lock'), true);
        $manifest = $checker->readManifest($source);
        $this->assertSame(array_column($lock['packages'], 'version', 'name'), array_column($manifest['packages'], 'version', 'name'));
        (new VendorBundleInstaller($checker))->install($source, $this->fixture);
        $installed = require $this->fixture.'/vendor/composer/installed.php';
        $this->assertSame('1.2.1', $installed['root']['pretty_version']);
        $this->assertSame('v4.19.0', $installed['versions']['ezyang/htmlpurifier']['pretty_version']);
        $this->assertFileExists($this->fixture.'/vendor/autoload.php');
    }

    public function test_import_plugin_pending_copy_has_no_external_vendor_requirements(): void
    {
        $source = $this->root.'/plugins/_bundled/yutiv-product_import';
        ExtensionPendingHelper::stageForUpdate($source, $this->fixture.'/plugin-pending');
        $this->assertFalse(app(ExtensionManager::class)->hasComposerDependenciesAt($this->fixture.'/plugin-pending'));
        $this->assertFileExists($this->fixture.'/plugin-pending/plugin.php');
        $this->assertFileExists($this->fixture.'/plugin-pending/dist/js/plugin.iife.js');
        $this->assertFileExists($this->fixture.'/plugin-pending/database/migrations/2026_10_09_000001_create_product_import_tables.php');
    }
}
