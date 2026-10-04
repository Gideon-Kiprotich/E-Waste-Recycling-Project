<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

return new class extends Migration
{
    public function up(): void
    {
        // Preserve foundation accounts and session associations while moving to D3 UUIDs.
        Schema::table('users', fn (Blueprint $table) => $table->uuid('account_uuid')->nullable());
        Schema::table('sessions', fn (Blueprint $table) => $table->uuid('account_user_id')->nullable());

        DB::table('users')->orderBy('id')->each(function (object $user): void {
            $uuid = (string) Str::uuid();
            DB::table('users')->where('id', $user->id)->update(['account_uuid' => $uuid]);
            DB::table('sessions')->where('user_id', $user->id)->update(['account_user_id' => $uuid]);
        });

        Schema::table('sessions', function (Blueprint $table): void {
            $table->dropColumn('user_id');
            $table->renameColumn('account_user_id', 'user_id');
        });
        Schema::table('users', function (Blueprint $table): void {
            $table->dropColumn('id');
            $table->renameColumn('account_uuid', 'id');
            $table->renameColumn('name', 'display_name');
            $table->renameColumn('password', 'password_hash');
        });
        Schema::table('users', function (Blueprint $table): void {
            $table->uuid('id')->nullable(false)->change();
            $table->primary('id');
            $table->text('display_name')->nullable()->change();
            $table->string('email', 254)->change();
            $table->text('password_hash')->change();
            $table->text('status')->default('active');
            $table->bigInteger('version')->default(1);
            $table->bigInteger('session_version')->default(1);
            $table->timestampTz('created_at')->nullable()->change();
            $table->timestampTz('updated_at')->nullable()->change();
            $table->timestampTz('email_verified_at')->nullable()->change();
        });
        Schema::table('sessions', fn (Blueprint $table) => $table->index('user_id'));
        DB::statement("ALTER TABLE users ADD CONSTRAINT users_status_check CHECK (status IN ('active', 'suspended', 'redacted'))");
        DB::statement('ALTER TABLE users ADD CONSTRAINT users_version_check CHECK (version >= 1 AND session_version >= 1)');
        DB::statement('CREATE UNIQUE INDEX users_email_normalized_unique ON users (lower(email))');

        Schema::create('memberships', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('user_id')->unique()->constrained()->restrictOnDelete();
            $table->text('role');
            $table->boolean('active')->default(true);
            $table->timestampsTz();
        });
        DB::statement("ALTER TABLE memberships ADD CONSTRAINT memberships_role_check CHECK (role IN ('consumer', 'collector', 'processor', 'administrator'))");
        Schema::create('notice_acknowledgments', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('user_id')->constrained()->restrictOnDelete();
            $table->text('notice_version');
            $table->timestampTz('acknowledged_at');
            $table->unique(['user_id', 'notice_version']);
        });
    }

    public function down(): void
    {
        throw new RuntimeException('UUID account identifiers cannot be safely reverted. Use a reviewed forward migration.');
    }
};
