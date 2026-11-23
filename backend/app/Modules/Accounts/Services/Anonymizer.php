<?php

namespace App\Modules\Accounts\Services;

use App\Models\User;
use App\Modules\Platform\Models\Attachment;
use App\Modules\Platform\Models\Setting;
use App\Modules\Platform\Support\Audit;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

/**
 * J-08 (BR-16): بعد المهلة تُجهَّل البيانات الشخصية. تبقى السجلات المالية (العروض وملفاتها وأمر الشراء)
 * وسجلات الشركات وسجل التدقيق الإلحاقي. الملفات تُمحى من التخزين بعد نجاح المعاملة.
 */
final class Anonymizer
{
    /** @return int عدد الحسابات التي جُهّلت */
    public function runDue(): int
    {
        $days = (int) Setting::read('account.deletion_grace_days', 30);
        $ids = User::query()->where('user_type', 'customer')->where('status', 'deleted')->whereNull('anonymized_at')
            ->where('deleted_at', '<=', now()->subDays($days))->pluck('id');

        return $ids->filter(fn (string $id) => $this->anonymize($id))->count();
    }

    private function anonymize(string $id): bool
    {
        $files = DB::transaction(function () use ($id) {
            $user = User::query()->lockForUpdate()->find($id);
            if (! $user || $user->status !== 'deleted' || $user->anonymized_at) {
                return null; // تشغيل مزدوج: لا أثر مكرر
            }
            $phone = $user->phone_e164;
            $email = $user->email;
            $personalRfqs = DB::table('rfqs')->where('customer_user_id', $id)->whereNull('organization_id')->pluck('id');
            $personalFleet = DB::table('customer_equipment')->where('owner_user_id', $id)->pluck('id');

            // قبل مسح الروابط
            $files = Attachment::query()->where('uploaded_by', $id)->whereNotIn('id', $this->retainedFiles($id))->get();

            $user->forceFill([
                'full_name' => 'حساب محذوف', 'phone_e164' => null, 'phone_verified_at' => null, 'email' => null,
                'email_verified_at' => null, 'password_hash' => null, 'avatar_id' => null, 'suspended_reason' => null,
                'anonymized_at' => now(),
            ])->save();

            DB::table('addresses')->where('user_id', $id)->update([
                'label' => '—', 'wilayat' => '—', 'description' => null, 'location' => null,
                'contact_name' => null, 'contact_phone' => null, 'archived_at' => now(),
            ]);
            DB::table('customer_equipment')->whereIn('id', $personalFleet)->update([
                'serial_number' => null, 'nickname' => null, 'nameplate_photo_id' => null, 'site_address_id' => null, 'archived_at' => now(),
            ]);
            DB::table('hour_meter_readings')->whereIn('customer_equipment_id', $personalFleet)->update(['note' => null, 'photo_id' => null]);
            DB::table('rfqs')->whereIn('id', $personalRfqs)->update(['notes' => null, 'delivery_address_text' => null, 'cancel_reason' => null]);
            DB::table('sales_orders')->where('customer_user_id', $id)->whereNull('organization_id')->update(['delivery_address' => null, 'notes' => null]);
            DB::table('return_requests')->where('created_by', $id)->update(['reason' => '—']);
            DB::table('messages')->where('sender_user_id', $id)
                ->whereIn('conversation_id', DB::table('conversations')->whereIn('rfq_id', $personalRfqs)->select('id'))
                ->update(['body' => null]);
            DB::table('customer_notes')->where('user_id', $id)->update(['body' => '—']);

            DB::table('notifications')->where('user_id', $id)->delete(); // التسليمات تُحذف معها
            DB::table('notification_preferences')->where('user_id', $id)->delete();
            DB::table('data_exports')->where('user_id', $id)->update(['status' => 'expired', 'attachment_id' => null]);
            DB::table('sessions')->where('user_id', $id)->update(['ip' => null, 'user_agent' => null]);
            DB::table('consents')->where('user_id', $id)->update(['ip' => null]); // يبقى إثبات الموافقة دون العنوان
            if ($phone) {
                DB::table('otp_challenges')->where('phone_e164', $phone)->delete();
                DB::table('organization_invitations')->where('phone_e164', $phone)->update(['status' => DB::raw("IF(status = 'pending', 'revoked', status)")]);
                DB::table('organization_invitations')->where('phone_e164', $phone)->update(['phone_e164' => null]);
            }
            if ($email) {
                DB::table('password_reset_tokens')->where('email', $email)->delete();
            }
            DB::table('attachments')->whereIn('id', $files->pluck('id'))->update(['original_name' => null]);
            Audit::record('account.anonymized', 'user', $id);

            return $files;
        });
        if ($files === null) {
            return false;
        }
        foreach ($files as $file) {
            Storage::disk($file->visibility === 'public' ? 'public' : 'local')->delete($file->storage_key);
        }

        return true;
    }

    /** مرفقات تبقى: المستندات المالية، وما يخص سجلات الشركات @return list<string> */
    private function retainedFiles(string $userId): array
    {
        $orgRfqs = DB::table('rfqs')->whereNotNull('organization_id')->select('id');

        return collect()
            ->merge(DB::table('quotations as q')->join('attachments as a', 'a.id', '=', 'q.lpo_attachment_id')->where('a.uploaded_by', $userId)->pluck('a.id'))
            ->merge(DB::table('rfq_attachments as r')->join('attachments as a', 'a.id', '=', 'r.attachment_id')
                ->where('a.uploaded_by', $userId)->whereIn('r.rfq_id', clone $orgRfqs)->pluck('a.id'))
            ->merge(DB::table('message_attachments as m')->join('attachments as a', 'a.id', '=', 'm.attachment_id')
                ->join('messages as g', 'g.id', '=', 'm.message_id')->join('conversations as c', 'c.id', '=', 'g.conversation_id')
                ->where('a.uploaded_by', $userId)->whereIn('c.rfq_id', clone $orgRfqs)->pluck('a.id'))
            ->merge(DB::table('customer_equipment as e')->join('attachments as a', 'a.id', '=', 'e.nameplate_photo_id')
                ->where('a.uploaded_by', $userId)->whereNotNull('e.organization_id')->pluck('a.id'))
            ->merge(DB::table('hour_meter_readings as h')->join('attachments as a', 'a.id', '=', 'h.photo_id')
                ->join('customer_equipment as e', 'e.id', '=', 'h.customer_equipment_id')
                ->where('a.uploaded_by', $userId)->whereNotNull('e.organization_id')->pluck('a.id'))
            ->unique()->values()->all();
    }
}
