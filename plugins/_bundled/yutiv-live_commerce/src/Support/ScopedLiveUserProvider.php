<?php

namespace Plugins\Yutiv\LiveCommerce\Support;

use Illuminate\Auth\EloquentUserProvider;

class ScopedLiveUserProvider extends EloquentUserProvider
{
    public function retrieveByCredentials(array $credentials)
    {
        $tenant = LiveContext::tenant();
        if (! $tenant) {
            return null;
        }
        $identifier = $credentials['email'] ?? '';
        unset($credentials['email']);
        $credentials[str_contains($identifier, '@') ? 'email' : 'username'] = $identifier;
        $credentials['tenant_id'] = $tenant->id;

        return parent::retrieveByCredentials($credentials);
    }

    public function retrieveById($identifier)
    {
        $user = parent::retrieveById($identifier);

        return $user && $user->tenant_id === LiveContext::tenant()?->id ? $user : null;
    }

    public function retrieveByToken($identifier, $token)
    {
        $user = parent::retrieveByToken($identifier, $token);

        return $user && $user->tenant_id === LiveContext::tenant()?->id ? $user : null;
    }
}
