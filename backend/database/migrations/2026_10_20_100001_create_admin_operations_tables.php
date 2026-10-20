<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Admin control center storage: service health history, alerts, incidents (with timeline), the administrative task
 * center, data-quality findings, maintenance windows and backup integrity metadata.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('health_check_results', function (Blueprint $table) {
            $table->id();
            $table->string('service', 32);
            $table->string('status', 16); // healthy | degraded | unavailable | unconfigured | unknown
            $table->unsignedInteger('response_ms')->nullable();
            $table->json('detail')->nullable(); // counts and exception class names only
            $table->timestamp('checked_at')->useCurrent();
            $table->index(['service', 'checked_at']);
            $table->index('checked_at');
        });

        Schema::create('admin_incidents', function (Blueprint $table) {
            $table->id();
            $table->string('title', 160);
            $table->string('category', 24)->index();  // availability | ai | security | data | email | performance | other
            $table->string('severity', 16)->index();  // critical | high | medium | low
            $table->string('status', 16)->index();    // open | investigating | mitigated | resolved | closed
            $table->string('service', 32)->nullable();
            $table->text('summary')->nullable();
            $table->text('root_cause')->nullable();
            $table->text('corrective_actions')->nullable();
            $table->foreignId('opened_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('assigned_to')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('opened_at')->useCurrent();
            $table->timestamp('resolved_at')->nullable();
            $table->timestamps();
        });

        Schema::create('admin_incident_events', function (Blueprint $table) {
            $table->id();
            $table->foreignId('incident_id')->constrained('admin_incidents')->cascadeOnDelete();
            $table->foreignId('user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('type', 24); // note | status | severity | assignment | alert_linked | created
            $table->text('body')->nullable();
            $table->json('metadata')->nullable();
            $table->timestamp('created_at')->useCurrent();
            $table->index(['incident_id', 'created_at']);
        });

        Schema::create('admin_alerts', function (Blueprint $table) {
            $table->id();
            $table->string('rule', 48)->index();
            $table->string('dedupe_key', 191)->unique();
            $table->string('severity', 16);           // info | warning | critical
            $table->string('service', 32)->nullable();
            $table->string('title', 160);
            $table->string('message', 500);
            $table->string('status', 16)->index();    // open | acknowledged | resolved
            $table->unsignedInteger('occurrences')->default(1);
            $table->json('last_value')->nullable();
            $table->timestamp('first_seen_at');
            $table->timestamp('last_seen_at');
            $table->boolean('suppressed')->default(false); // raised during a maintenance window: recorded, not notified
            $table->timestamp('notified_at')->nullable();
            $table->foreignId('acknowledged_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('acknowledged_at')->nullable();
            $table->foreignId('resolved_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('resolved_at')->nullable();
            $table->string('resolution_note', 500)->nullable();
            $table->foreignId('incident_id')->nullable()->constrained('admin_incidents')->nullOnDelete();
            $table->timestamps();
        });

        Schema::create('admin_operations', function (Blueprint $table) {
            $table->id();
            $table->string('type', 40)->index();      // user_import | export | backup | data_quality_scan | health_check | alert_evaluation
            $table->string('label', 160);
            $table->string('status', 16)->index();    // running | completed | failed | cancelled
            $table->foreignId('initiated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->unsignedInteger('total_count')->nullable();
            $table->unsignedInteger('success_count')->nullable();
            $table->unsignedInteger('failure_count')->nullable();
            $table->json('summary')->nullable();      // safe, structured result details
            $table->string('error', 255)->nullable();
            $table->string('result_disk', 32)->nullable();
            $table->string('result_path', 255)->nullable();
            $table->string('result_filename', 191)->nullable();
            $table->timestamp('result_expires_at')->nullable();
            $table->timestamp('started_at')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->timestamps();
        });

        Schema::create('data_quality_findings', function (Blueprint $table) {
            $table->id();
            $table->string('check', 48)->index();
            $table->string('category', 24)->index();  // metadata | integrity | documents | ai | accounts
            $table->string('severity', 16)->index();  // high | medium | low
            $table->string('entity_type', 48);
            $table->unsignedBigInteger('entity_id')->nullable();
            $table->string('entity_label', 191)->nullable();
            $table->string('fingerprint', 191)->unique();
            $table->string('explanation', 500);
            $table->string('remediation', 500);
            $table->string('status', 16)->index();    // open | acknowledged | resolved | ignored
            $table->boolean('auto_resolved')->default(false);
            $table->timestamp('first_detected_at');
            $table->timestamp('last_detected_at');
            $table->foreignId('resolved_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('resolved_at')->nullable();
            $table->string('resolution_note', 500)->nullable();
            $table->timestamps();
            $table->index(['entity_type', 'entity_id']);
        });

        Schema::create('maintenance_windows', function (Blueprint $table) {
            $table->id();
            $table->string('title', 160);
            $table->text('description')->nullable();
            $table->json('services')->nullable();
            $table->timestamp('starts_at');
            $table->timestamp('ends_at');
            $table->boolean('suppress_alerts')->default(true);
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('cancelled_at')->nullable();
            $table->timestamps();
            $table->index(['starts_at', 'ends_at']);
        });

        Schema::table('database_backups', function (Blueprint $table) {
            $table->string('checksum_sha256', 64)->nullable()->after('size_bytes');
            $table->string('verification_status', 16)->nullable()->after('checksum_sha256'); // verified | failed
            $table->timestamp('verified_at')->nullable()->after('verification_status');
        });
    }

    public function down(): void
    {
        Schema::table('database_backups', function (Blueprint $table) {
            $table->dropColumn(['checksum_sha256', 'verification_status', 'verified_at']);
        });
        Schema::dropIfExists('maintenance_windows');
        Schema::dropIfExists('data_quality_findings');
        Schema::dropIfExists('admin_operations');
        Schema::dropIfExists('admin_alerts');
        Schema::dropIfExists('admin_incident_events');
        Schema::dropIfExists('admin_incidents');
        Schema::dropIfExists('health_check_results');
    }
};
