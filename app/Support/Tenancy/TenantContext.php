<?php

namespace App\Support\Tenancy;

use App\Models\Organization;
use LogicException;
use RuntimeException;

/**
 * Request-scoped tenant state. Set once per request; rejects accidental reassignment.
 */
final class TenantContext
{
    private static ?self $instance = null;

    /**
     * @param  list<string>  $parentPermissions
     * @param  list<string>  $organizationPermissions
     */
    private function __construct(
        public readonly int $userId,
        public readonly int $parentAccountId,
        public readonly int $organizationId,
        public readonly ?int $parentMembershipId,
        public readonly int $organizationMembershipId,
        public readonly Organization $organization,
        public readonly array $parentPermissions,
        public readonly array $organizationPermissions,
    ) {}

    /**
     * @param  list<string>  $parentPermissions
     * @param  list<string>  $organizationPermissions
     */
    public static function establish(
        int $userId,
        int $parentAccountId,
        int $organizationId,
        ?int $parentMembershipId,
        int $organizationMembershipId,
        Organization $organization,
        array $parentPermissions,
        array $organizationPermissions,
    ): self {
        if (self::$instance !== null) {
            throw new LogicException('TenantContext has already been established for this request.');
        }

        self::$instance = new self(
            userId: $userId,
            parentAccountId: $parentAccountId,
            organizationId: $organizationId,
            parentMembershipId: $parentMembershipId,
            organizationMembershipId: $organizationMembershipId,
            organization: $organization,
            parentPermissions: array_values(array_unique($parentPermissions)),
            organizationPermissions: array_values(array_unique($organizationPermissions)),
        );

        return self::$instance;
    }

    public static function has(): bool
    {
        return self::$instance !== null;
    }

    public static function get(): self
    {
        if (self::$instance === null) {
            throw new RuntimeException('TenantContext is not established. Legacy routes must not call TenantContext.');
        }

        return self::$instance;
    }

    public static function getOptional(): ?self
    {
        return self::$instance;
    }

    /**
     * Test-only reset between Pest cases / HTTP kernel cycles.
     */
    public static function reset(): void
    {
        self::$instance = null;
    }

    public function canOrg(string $permission): bool
    {
        return in_array($permission, $this->organizationPermissions, true);
    }

    public function canParent(string $permission): bool
    {
        return in_array($permission, $this->parentPermissions, true);
    }

    public function canViewCost(): bool
    {
        return $this->canOrg('catalog.product.view_cost')
            || $this->canParent('parent.catalog.product.view_cost');
    }

    /**
     * @return array{id: int, name: string, slug: string}
     */
    public function organizationSummary(): array
    {
        return [
            'id' => $this->organization->id,
            'name' => $this->organization->name,
            'slug' => $this->organization->slug,
        ];
    }
}
