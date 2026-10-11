<?php

namespace Modules\Yutiv\PersonalStore\Listeners;

use App\Contracts\Extension\HookListenerInterface;
use Modules\Yutiv\PersonalStore\Rules\MembershipProofRule;

class RegistrationListener implements HookListenerInterface
{
    public static function getSubscribedHooks(): array
    {
        return ['core.auth.register_validation_rules' => ['method' => 'rules', 'type' => 'filter']];
    }

    public function handle(...$args): void {}

    public function rules(array $rules): array
    {
        $rules['store_membership_proof'] = ['nullable', 'string', 'max:4096', new MembershipProofRule];

        return $rules;
    }
}
