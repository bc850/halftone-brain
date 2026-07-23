<?php

namespace App\Support\Audit;

use App\Models\AuditEvent;
use App\Models\Organization;
use App\Models\ParentAccount;
use App\Models\User;

final class Auditor
{
    public function __construct(private AuditRedactor $redactor) {}

    /**
     * @param  array<string, mixed>|null  $before
     * @param  array<string, mixed>|null  $after
     */
    public function append(
        ParentAccount $parentAccount,
        string $action,
        string $subjectType,
        ?int $subjectId = null,
        ?Organization $organization = null,
        ?User $actor = null,
        ?array $before = null,
        ?array $after = null,
        ?string $ip = null,
        ?string $userAgent = null,
        ?string $correlationId = null,
    ): AuditEvent {
        return AuditEvent::query()->create([
            'parent_account_id' => $parentAccount->id,
            'organization_id' => $organization?->id,
            'actor_user_id' => $actor?->id,
            'action' => $action,
            'subject_type' => $subjectType,
            'subject_id' => $subjectId,
            'before_json' => $before === null ? null : $this->redactor->redact($before),
            'after_json' => $after === null ? null : $this->redactor->redact($after),
            'ip' => $ip,
            'user_agent' => $userAgent,
            'correlation_id' => $correlationId,
        ]);
    }
}
