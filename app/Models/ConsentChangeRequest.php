<?php

namespace App\Models;

use App\Services\AuditTrail;
use App\Traits\BelongsToTenant;
use Carbon\Carbon;
use Database\Factories\ConsentChangeRequestFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

/**
 * A member's request, made from the portal, to change their consent. It changes nothing by itself:
 * staff act on it through the ordinary consent form and then close the request.
 */
class ConsentChangeRequest extends Model
{
    /** @use HasFactory<ConsentChangeRequestFactory> */
    use BelongsToTenant, HasFactory;

    public const TYPE_WITHDRAW_SCORING = 'withdraw_scoring';

    public const TYPE_GRANT_SCORING = 'grant_scoring';

    public const TYPE_WITHDRAW_DATA_PROCESSING = 'withdraw_data_processing';

    /**
     * Request types and their plain-language labels.
     *
     * @var array<string, string>
     */
    public const TYPE_LABELS = [
        self::TYPE_WITHDRAW_SCORING => 'Stop using my history for credit scoring',
        self::TYPE_GRANT_SCORING => 'Allow my history to be used for credit scoring',
        self::TYPE_WITHDRAW_DATA_PROCESSING => 'Stop the SACCO using my personal information',
    ];

    /**
     * Consent fields are not set from request input; rows are created only by openFor().
     *
     * @var list<string>
     */
    protected $guarded = ['*'];

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'handled_at' => 'datetime',
        ];
    }

    /**
     * Open a request for a member and write it to the audit trail. The SACCO is taken from the
     * member, because a portal session has no staff user to infer it from.
     */
    public static function openFor(Member $member, string $type, ?string $note): self
    {
        if (! array_key_exists($type, self::TYPE_LABELS)) {
            throw new InvalidArgumentException("Unknown consent request type [{$type}].");
        }

        return DB::transaction(function () use ($member, $type, $note) {
            $request = (new self)->forceFill([
                'sacco_id' => $member->sacco_id,
                'member_id' => $member->id,
                'request_type' => $type,
                'member_note' => $note,
                'status' => 'pending',
            ]);
            $request->save();

            app(AuditTrail::class)->record('consent.change_requested', $request, [
                'member_number' => $member->member_number,
                'request_type' => $type,
                'requested_by' => 'member_portal',
            ]);

            return $request;
        });
    }

    /**
     * Close a pending request as processed or dismissed, recording the staff member and audit entry.
     */
    public function resolve(User $handler, string $outcome, ?string $handlerNote): void
    {
        if (! in_array($outcome, ['processed', 'dismissed'], true)) {
            throw new InvalidArgumentException("Unknown outcome [{$outcome}].");
        }

        if ($this->status !== 'pending') {
            throw new InvalidArgumentException('This request has already been handled.');
        }

        DB::transaction(function () use ($handler, $outcome, $handlerNote) {
            $this->forceFill([
                'status' => $outcome,
                'handled_by' => $handler->id,
                'handled_at' => Carbon::now(),
                'handler_note' => $handlerNote,
            ])->save();

            app(AuditTrail::class)->record('consent.change_request_'.$outcome, $this, [
                'member_number' => $this->member?->member_number,
                'request_type' => $this->request_type,
            ]);
        });
    }

    /**
     * Whether the request is still waiting for staff.
     */
    public function isPending(): bool
    {
        return $this->status === 'pending';
    }

    /**
     * Plain-language description of what was asked.
     */
    public function label(): string
    {
        return self::TYPE_LABELS[$this->request_type] ?? $this->request_type;
    }

    /**
     * Get the member who made the request.
     */
    public function member(): BelongsTo
    {
        return $this->belongsTo(Member::class);
    }

    /**
     * Get the staff user who handled the request.
     */
    public function handler(): BelongsTo
    {
        return $this->belongsTo(User::class, 'handled_by');
    }
}
