<?php

namespace App\Providers;

use Anthropic\Client as AnthropicClient;
use App\Contracts\OcrServiceInterface;
use App\Services\Ocr\ClaudeOcrService;
use App\Services\Ocr\FakeOcrService;
use GuzzleHttp\Client as GuzzleClient;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;
use Illuminate\Validation\Rules\Password;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        $this->app->bind(OcrServiceInterface::class, function () {
            if (config('services.ocr.driver') !== 'claude') {
                return new FakeOcrService;
            }

            // El SDK no aplica el timeout: lo hace el cliente HTTP. Sin reintentos, para no pasar de
            // los 20 s de CU07; si falla, el almacenero sigue con el registro manual (CU08).
            $cliente = new AnthropicClient(
                apiKey: (string) config('services.anthropic.key'),
                requestOptions: [
                    'transporter' => new GuzzleClient([
                        'timeout' => config('services.ocr.timeout'),
                        'connect_timeout' => 5,
                    ]),
                    'maxRetries' => 0,
                ],
            );

            return new ClaudeOcrService($cliente, (string) config('services.anthropic.ocr_model'));
        });
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        // Detecta consultas N+1 y atributos inexistentes durante el desarrollo.
        Model::shouldBeStrict(! $this->app->isProduction());

        JsonResource::withoutWrapping();

        Password::defaults(fn () => Password::min(8)->letters()->numbers());

        RateLimiter::for('ocr', fn (Request $request) => Limit::perMinute(config('services.ocr.por_minuto'))
            ->by($request->user()?->id ?: $request->ip()));

        RateLimiter::for('reportes', fn (Request $request) => Limit::perMinute(config('services.reportes.por_minuto'))
            ->by($request->user()?->id ?: $request->ip()));
    }
}
