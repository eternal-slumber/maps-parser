<?php

namespace App\Http\Requests;

use App\Models\User;
use App\Services\YandexMapsUrlResolver;
use Closure;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use InvalidArgumentException;
use LogicException;
use RuntimeException;

final class StoreOrganizationRequest extends FormRequest
{
    private ?string $businessId = null;

    public function authorize(): bool
    {
        return $this->user() instanceof User;
    }

    /**
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(YandexMapsUrlResolver $urlResolver): array
    {
        return [
            'url' => [
                'bail',
                'required',
                'string',
                'max:2048',
                'url:http,https',
                function (string $attribute, mixed $value, Closure $fail) use ($urlResolver): void {
                    if (! is_string($value)) {
                        return;
                    }

                    try {
                        $this->businessId = $urlResolver->resolveBusinessId($value);
                    } catch (InvalidArgumentException) {
                        $fail('Укажите ссылку на организацию в Яндекс Картах.');
                    } catch (RuntimeException) {
                        $fail('Не удалось открыть сокращённую ссылку Яндекс Карт.');
                    }
                },
            ],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'url.required' => 'Укажите ссылку на организацию.',
            'url.string' => 'Ссылка должна быть строкой.',
            'url.max' => 'Ссылка не должна быть длиннее 2048 символов.',
            'url.url' => 'Укажите корректную HTTP(S)-ссылку.',
        ];
    }

    public function businessId(): string
    {
        return $this->businessId
            ?? throw new LogicException('businessId недоступен до успешной валидации.');
    }

    public function organizationUrl(): string
    {
        return "https://yandex.ru/maps/org/{$this->businessId()}/";
    }
}
