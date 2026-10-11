<?php

namespace Modules\Yutiv\PersonalStore\Repositories\Contracts;

interface StoreRepositoryInterface
{
    public function lockRevision(): int;

    public function path(int $storeId): array;

    public function allowed(int $storeId, int $productId): bool;

    public function snapshot(array $data): void;

    public function outbox(string $key, string $type, array $payload): void;

    public function move(int $storeId, ?int $parentId, int $actorId): void;

    public function rebuildClosure(bool $repair = false): array;

    public function attribution(?string $key): ?int;

    public function productRate(int $productId): int;

    public function captureOrderState(int $orderId): void;

    public function processOutbox(int $id): bool;

    public function manage(int $actorId, string $action, array $data): array;

    public function dashboard(int $actorId, array $filters): array;

    public function publicStore(string $slug): array;

    public function publicOrigin(string $slug): string;

    public function products(string $slug, array $filters): array;

    public function detail(string $slug, string $code): array;

    public function registerMembership(int $userId, string $reference, ?string $proofHash = null): void;

    public function validateMembershipReference(string $reference): int;

    public function headquarters(int $actorId, array $filters): array;

    public function catalog(int $actorId, array $filters): array;

    public function categories(int $actorId, array $filters): array;
}
