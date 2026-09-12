<script setup lang="ts">
import {
    show,
    store,
} from '@/actions/App/Http/Controllers/OrganizationController';
import { Head, useHttp } from '@inertiajs/vue3';
import { computed, onUnmounted, ref } from 'vue';

type Organization = {
    id: number;
    business_id: string;
    name: string | null;
    rating: number;
    rating_count: number;
    review_count: number;
    sync_status: 'pending' | 'processing' | 'completed' | 'failed';
    processed_pages: number;
    processed_reviews: number;
    sync_error: string | null;
};

type OrganizationResponse = {
    data: Organization;
};

const createRequest = useHttp<{ url: string }, OrganizationResponse>({
    url: '',
});
const statusRequest = useHttp<Record<string, never>, OrganizationResponse>({});
const organization = ref<Organization | null>(null);
const requestError = ref<string | null>(null);
let pollTimer: ReturnType<typeof setTimeout> | null = null;

const isSyncing = computed(() =>
    ['pending', 'processing'].includes(organization.value?.sync_status ?? ''),
);

const statusLabel = computed(() => {
    switch (organization.value?.sync_status) {
        case 'pending':
            return 'Ожидает запуска';
        case 'processing':
            return 'Загружаем отзывы';
        case 'completed':
            return 'Готово';
        case 'failed':
            return 'Ошибка';
        default:
            return '';
    }
});

function clearPollTimer(): void {
    if (pollTimer !== null) {
        clearTimeout(pollTimer);
        pollTimer = null;
    }
}

function schedulePoll(): void {
    clearPollTimer();

    if (isSyncing.value) {
        pollTimer = setTimeout(poll, 2000);
    }
}

async function poll(): Promise<void> {
    if (organization.value === null) {
        return;
    }

    try {
        await statusRequest.get(show(organization.value.id).url, {
            onSuccess: (response) => {
                organization.value = response.data;
                schedulePoll();
            },
            onHttpException: () => {
                requestError.value = 'Не удалось обновить статус.';
            },
            onNetworkError: () => {
                requestError.value = 'Нет соединения с сервером.';
            },
        });
    } catch {
        clearPollTimer();
    }
}

async function submit(): Promise<void> {
    clearPollTimer();
    requestError.value = null;

    try {
        await createRequest.post(store().url, {
            onSuccess: (response) => {
                organization.value = response.data;
                schedulePoll();
            },
            onHttpException: (response) => {
                requestError.value =
                    response.status === 401
                        ? 'Для запуска импорта нужна авторизация.'
                        : 'Не удалось запустить импорт.';
            },
            onNetworkError: () => {
                requestError.value = 'Нет соединения с сервером.';
            },
        });
    } catch {
        // Ошибка уже показана через useHttp.
    }
}

onUnmounted(() => {
    clearPollTimer();
    createRequest.cancel();
    statusRequest.cancel();
});
</script>

<template>
    <Head title="Парсер отзывов" />

    <main class="min-h-screen bg-zinc-50 px-5 py-16 text-zinc-950 sm:py-24">
        <div class="mx-auto max-w-2xl">
            <header class="mb-10">
                <h1 class="text-3xl font-semibold tracking-tight sm:text-4xl">
                    Отзывы Яндекс Карт
                </h1>
                <p class="mt-3 max-w-xl text-base leading-7 text-zinc-600">
                    Вставьте ссылку на организацию. Отзывы загрузятся в фоне.
                </p>
            </header>

            <form
                class="border-b border-zinc-200 pb-10"
                @submit.prevent="submit"
            >
                <label for="organization-url" class="block text-sm font-medium">
                    Ссылка на организацию
                </label>

                <div class="mt-2 flex flex-col gap-3 sm:flex-row">
                    <input
                        id="organization-url"
                        v-model="createRequest.url"
                        type="url"
                        name="url"
                        required
                        autocomplete="url"
                        placeholder="https://yandex.ru/maps/org/..."
                        class="min-w-0 flex-1 rounded-md border border-zinc-300 bg-white px-3 py-2.5 text-base outline-none placeholder:text-zinc-400 focus:border-zinc-950 focus:ring-2 focus:ring-zinc-950/10"
                        :aria-invalid="
                            createRequest.errors.url ? 'true' : undefined
                        "
                        aria-describedby="url-error"
                    />
                    <button
                        type="submit"
                        :disabled="createRequest.processing"
                        class="rounded-md bg-zinc-950 px-5 py-2.5 text-sm font-medium text-white hover:bg-zinc-800 focus:ring-2 focus:ring-zinc-950 focus:ring-offset-2 focus:outline-none disabled:cursor-not-allowed disabled:opacity-60"
                    >
                        {{
                            createRequest.processing
                                ? 'Запускаем…'
                                : 'Загрузить'
                        }}
                    </button>
                </div>

                <p
                    v-if="createRequest.errors.url"
                    id="url-error"
                    class="mt-2 text-sm text-red-700"
                >
                    {{ createRequest.errors.url }}
                </p>
                <p v-else-if="requestError" class="mt-2 text-sm text-red-700">
                    {{ requestError }}
                </p>
            </form>

            <section v-if="organization" class="pt-10" aria-live="polite">
                <div
                    class="flex flex-col gap-2 sm:flex-row sm:items-baseline sm:justify-between"
                >
                    <h2 class="text-xl font-semibold">
                        {{
                            organization.name ??
                            `Организация ${organization.business_id}`
                        }}
                    </h2>
                    <p class="text-sm font-medium text-zinc-600">
                        {{ statusLabel }}
                    </p>
                </div>

                <dl class="mt-6 grid grid-cols-1 gap-5 sm:grid-cols-3">
                    <div>
                        <dt class="text-sm text-zinc-500">Рейтинг</dt>
                        <dd class="mt-1 text-2xl font-semibold">
                            {{ organization.rating || '—' }}
                        </dd>
                    </div>
                    <div>
                        <dt class="text-sm text-zinc-500">Оценки</dt>
                        <dd class="mt-1 text-2xl font-semibold">
                            {{ organization.rating_count }}
                        </dd>
                    </div>
                    <div>
                        <dt class="text-sm text-zinc-500">Отзывы</dt>
                        <dd class="mt-1 text-2xl font-semibold">
                            {{ organization.review_count }}
                        </dd>
                    </div>
                </dl>

                <p v-if="isSyncing" class="mt-6 text-sm text-zinc-600">
                    Обработано страниц: {{ organization.processed_pages }} ·
                    отзывов:
                    {{ organization.processed_reviews }}
                </p>
                <p
                    v-else-if="organization.sync_error"
                    class="mt-6 text-sm text-red-700"
                >
                    {{ organization.sync_error }}
                </p>
            </section>
        </div>
    </main>
</template>
