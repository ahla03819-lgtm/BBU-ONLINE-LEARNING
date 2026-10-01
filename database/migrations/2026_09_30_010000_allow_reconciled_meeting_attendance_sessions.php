<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Forward-only change to the Phase 5 attendance table.
 *
 * A student screen-share request is authorised against live provider presence,
 * so an attendance session can be opened by reconciliation rather than by a
 * delivered participant_joined webhook. join_webhook_event_id therefore becomes
 * nullable. It is NOT NULL today only because every historical row was
 * webhook-created.
 *
 * No fabricated webhook event is ever inserted: a reconciled row keeps
 * join_webhook_event_id NULL until the real participant_joined webhook for the
 * same provider participant SID adopts it.
 *
 * Uniqueness is preserved for every non-null value. MySQL and PostgreSQL both
 * allow repeated NULLs in a unique index, so attendance_join_webhook_unique
 * still rejects two sessions claiming the same join webhook event.
 *
 * The (meeting_participant_id, livekit_participant_sid) idempotency key is
 * reused rather than re-added: attendance_participant_sid_unique already exists
 * on this table and is exactly the constraint that stops reconciliation and a
 * later participant_joined webhook from opening two sessions for one provider
 * participant SID.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('meeting_attendance_sessions', function (Blueprint $table) {
            $table->uuid('join_webhook_event_id')->nullable()->change();
        });
    }

    public function down(): void
    {
        // Rows opened by reconciliation have no webhook to point back to, so the
        // column cannot be made NOT NULL again without discarding them.
        Schema::table('meeting_attendance_sessions', function (Blueprint $table) {
            $table->uuid('join_webhook_event_id')->nullable(false)->change();
        });
    }
};
