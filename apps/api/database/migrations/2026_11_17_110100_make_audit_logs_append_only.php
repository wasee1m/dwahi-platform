<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

// سجل التدقيق إلحاقي (NFR-10، US-44): قاعدة البيانات نفسها ترفض التعديل والحذف، حتى من المدير.
// يتطلب log_bin_trust_function_creators=1 لأن السجل الثنائي مفعّل في MySQL 8.4 (docker-compose.yml).
return new class extends Migration
{
    public function up(): void
    {
        foreach (['UPDATE', 'DELETE'] as $op) {
            $name = 'audit_logs_no_'.strtolower($op);
            DB::unprepared("DROP TRIGGER IF EXISTS {$name}");
            DB::unprepared("CREATE TRIGGER {$name} BEFORE {$op} ON audit_logs FOR EACH ROW
                SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'audit_logs is append-only'");
        }
    }

    public function down(): void
    {
        DB::unprepared('DROP TRIGGER IF EXISTS audit_logs_no_update');
        DB::unprepared('DROP TRIGGER IF EXISTS audit_logs_no_delete');
    }
};
