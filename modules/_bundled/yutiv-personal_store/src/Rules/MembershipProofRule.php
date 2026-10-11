<?php

namespace Modules\Yutiv\PersonalStore\Rules;

use Closure;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Support\Facades\DB;
use Modules\Yutiv\PersonalStore\Repositories\Contracts\StoreRepositoryInterface;
use Modules\Yutiv\PersonalStore\Services\AttributionProof;

class MembershipProofRule implements ValidationRule
{
    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        try {
            $reference = app(AttributionProof::class)->verify($value, 'membership');
            if ($reference !== null) {
                app(StoreRepositoryInterface::class)->validateMembershipReference($reference);
                if (DB::table('personal_store_memberships')->where('proof_hash', hash('sha256', $value))->exists()) {
                    throw new \RuntimeException;
                }
            }
        } catch (\Throwable) {
            $fail(__('yutiv-personal_store::messages.invalid_proof'));
        }
    }
}
