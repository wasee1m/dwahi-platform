<?php

namespace App\Modules\Platform\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Redis;
use Illuminate\Support\Facades\Storage;
use Throwable;

/**
 * فحص صحة للمراقبة الخارجية (NFR-04، NFR-20): قاعدة البيانات و Redis والتخزين حرجة (503 إن تعطل أحدها)،
 * والطابور والمجدول تحذيران. لا بيانات حساسة في الرد، والمسار عام بحد طلبات.
 */
class HealthController
{
    /** المجدول يكتب نبضة كل دقيقة (routes/console.php) */
    public const HEARTBEAT = 'health:scheduler';

    public function __invoke(): JsonResponse
    {
        $checks = [];
        $time = function (string $name, callable $fn) use (&$checks) {
            $t = microtime(true);
            try {
                $detail = $fn();
                $checks[$name] = ['ok' => true, 'ms' => (int) round((microtime(true) - $t) * 1000)] + (is_array($detail) ? $detail : []);
            } catch (Throwable $e) {
                $checks[$name] = ['ok' => false, 'error' => class_basename($e)];
            }
        };
        $time('database', function () {
            DB::select('SELECT 1');
        });
        $time('redis', fn () => Redis::connection()->ping());
        $time('storage', function () {
            $key = 'health/probe.txt';
            Storage::disk('local')->put($key, (string) time());
            Storage::disk('local')->delete($key);
        });
        $time('queue', function () {
            $size = Queue::size();
            $failed = DB::table('failed_jobs')->where('failed_at', '>=', now()->subHour())->count();
            if ($size > 500 || $failed > 20) {
                throw new \RuntimeException('backlog');
            }

            return ['size' => $size, 'failed_last_hour' => $failed];
        });
        $time('scheduler', function () {
            $beat = Cache::get(self::HEARTBEAT);
            if (! $beat || now()->diffInMinutes($beat) > 5) {
                throw new \RuntimeException('stale');
            }

            return ['last_beat' => $beat];
        });
        $critical = ! $checks['database']['ok'] || ! $checks['redis']['ok'] || ! $checks['storage']['ok'];
        $status = $critical ? 'down' : (in_array(false, array_column($checks, 'ok'), true) ? 'degraded' : 'ok');

        return response()->json(['status' => $status, 'checks' => $checks, 'time' => now()->toIso8601ZuluString()], $critical ? 503 : 200);
    }
}
