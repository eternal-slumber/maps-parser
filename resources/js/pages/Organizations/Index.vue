<script setup lang="ts">
import {
    destroy as destroyOrganization,
    index as organizationIndex,
    show,
    store,
} from '@/actions/App/Http/Controllers/OrganizationController';
import { destroy as logout } from '@/actions/App/Http/Controllers/Auth/AuthenticatedSessionController';
import { index as reviewIndex } from '@/actions/App/Http/Controllers/ReviewController';
import { Head, Link, useHttp } from '@inertiajs/vue3';
import { computed, onMounted, onUnmounted, ref } from 'vue';

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

type OrganizationsResponse = {
    data: Organization[];
};

type Review = {
    id: number;
    external_id: string;
    author_name: string | null;
    text: string | null;
    rating: number;
    published_at: string;
};

type PaginationMeta = {
    current_page: number;
    last_page: number;
    per_page: number;
    total: number;
};

type ReviewsResponse = {
    data: Review[];
    meta: PaginationMeta;
};

const createRequest = useHttp<{ url: string }, OrganizationResponse>({
    url: '',
});
const organizationsRequest = useHttp<
    Record<string, never>,
    OrganizationsResponse
>({});
const statusRequest = useHttp<Record<string, never>, OrganizationResponse>({});
const reviewsRequest = useHttp<Record<string, never>, ReviewsResponse>({});
const deleteRequest = useHttp<Record<string, never>>({});
const organizations = ref<Organization[]>([]);
const organization = ref<Organization | null>(null);
const reviews = ref<Review[]>([]);
const pagination = ref<PaginationMeta | null>(null);
const ratingFilter = ref('');
const requestError = ref<string | null>(null);
const reviewsError = ref<string | null>(null);
let pollTimer: ReturnType<typeof setTimeout> | null = null;
const dateFormatter = new Intl.DateTimeFormat('ru-RU', {
    day: 'numeric',
    month: 'long',
    year: 'numeric',
});

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

const reviewPages = computed(() =>
    Array.from(
        { length: pagination.value?.last_page ?? 0 },
        (_, index) => index + 1,
    ),
);

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

    const organizationId = organization.value.id;

    try {
        await statusRequest.get(show(organizationId).url, {
            onSuccess: (response) => {
                organizations.value = organizations.value.map((item) =>
                    item.id === response.data.id ? response.data : item,
                );

                if (organization.value?.id !== organizationId) {
                    return;
                }

                organization.value = response.data;
                requestError.value = null;

                if (response.data.sync_status === 'completed') {
                    void loadReviews();
                }

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

function activateOrganization(selectedOrganization: Organization): void {
    clearPollTimer();
    organization.value = selectedOrganization;
    reviews.value = [];
    pagination.value = null;
    requestError.value = null;
    reviewsError.value = null;

    if (isSyncing.value) {
        schedulePoll();
    } else if (selectedOrganization.sync_status === 'completed') {
        void loadReviews();
    }
}

async function loadOrganizations(): Promise<void> {
    requestError.value = null;

    try {
        await organizationsRequest.get(organizationIndex().url, {
            onSuccess: (response) => {
                organizations.value = response.data;

                const selectedOrganization = response.data[0];

                if (selectedOrganization) {
                    activateOrganization(selectedOrganization);
                }
            },
            onHttpException: () => {
                requestError.value = 'Не удалось загрузить организации.';
            },
            onNetworkError: () => {
                requestError.value = 'Нет соединения с сервером.';
            },
        });
    } catch {
        // Ошибка уже показана через useHttp.
    }
}

function changeOrganization(event: Event): void {
    const organizationId = Number((event.target as HTMLSelectElement).value);
    const selectedOrganization = organizations.value.find(
        (item) => item.id === organizationId,
    );

    if (selectedOrganization) {
        activateOrganization(selectedOrganization);
    }
}

async function deleteOrganization(): Promise<void> {
    if (organization.value === null) {
        return;
    }

    const organizationId = organization.value.id;
    const organizationName =
        organization.value.name ??
        `Организация ${organization.value.business_id}`;

    if (!window.confirm(`Удалить «${organizationName}» и все её отзывы?`)) {
        return;
    }

    clearPollTimer();
    statusRequest.cancel();
    reviewsRequest.cancel();
    requestError.value = null;

    try {
        await deleteRequest.delete(destroyOrganization(organizationId).url, {
            onSuccess: () => {
                organizations.value = organizations.value.filter(
                    (item) => item.id !== organizationId,
                );
                organization.value = null;
                reviews.value = [];
                pagination.value = null;

                const nextOrganization = organizations.value[0];

                if (nextOrganization) {
                    activateOrganization(nextOrganization);
                }
            },
            onHttpException: () => {
                requestError.value = 'Не удалось удалить организацию.';
            },
            onNetworkError: () => {
                requestError.value = 'Нет соединения с сервером.';
            },
        });
    } catch {
        if (organization.value?.id === organizationId) {
            schedulePoll();
        }
    }
}

async function loadReviews(page = 1): Promise<void> {
    if (organization.value?.sync_status !== 'completed') {
        return;
    }

    const organizationId = organization.value.id;
    const selectedRating = ratingFilter.value;
    reviewsError.value = null;

    try {
        await reviewsRequest.get(
            reviewIndex(organizationId, {
                query: {
                    page,
                    ...(selectedRating
                        ? { rating: Number(selectedRating) }
                        : {}),
                },
            }).url,
            {
                onSuccess: (response) => {
                    if (
                        organization.value?.id !== organizationId ||
                        ratingFilter.value !== selectedRating
                    ) {
                        return;
                    }

                    reviews.value = response.data;
                    pagination.value = response.meta;
                },
                onHttpException: () => {
                    reviewsError.value = 'Не удалось загрузить отзывы.';
                },
                onNetworkError: () => {
                    reviewsError.value = 'Нет соединения с сервером.';
                },
            },
        );
    } catch {
        // Ошибка уже показана через useHttp.
    }
}

async function submit(): Promise<void> {
    clearPollTimer();
    requestError.value = null;
    reviews.value = [];
    pagination.value = null;

    try {
        await createRequest.post(store().url, {
            onSuccess: (response) => {
                organizations.value = [
                    response.data,
                    ...organizations.value.filter(
                        (item) => item.id !== response.data.id,
                    ),
                ];
                activateOrganization(response.data);
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

function formatDate(date: string): string {
    return dateFormatter.format(new Date(date));
}

function reviewRatingClass(rating: number): string {
    if (rating >= 4) {
        return 'bg-emerald-50 text-emerald-700 ring-emerald-600/20';
    }

    if (rating === 3) {
        return 'bg-amber-50 text-amber-700 ring-amber-600/20';
    }

    return 'bg-red-50 text-red-700 ring-red-600/20';
}

onMounted(() => {
    void loadOrganizations();
});

onUnmounted(() => {
    clearPollTimer();
    createRequest.cancel();
    organizationsRequest.cancel();
    statusRequest.cancel();
    reviewsRequest.cancel();
    deleteRequest.cancel();
});
</script>

<template>
    <Head title="Парсер отзывов" />

    <main class="min-h-screen bg-zinc-50 px-5 py-16 text-zinc-950 sm:py-24">
        <div class="mx-auto max-w-2xl">
            <header class="mb-10 flex items-start justify-between gap-6">
                <div>
                    <h1
                        class="text-3xl font-semibold tracking-tight sm:text-4xl"
                    >
                        Отзывы Яндекс Карт
                    </h1>
                    <p class="mt-3 max-w-xl text-base leading-7 text-zinc-600">
                        Вставьте ссылку на организацию. Отзывы загрузятся в
                        фоне.
                    </p>
                </div>
                <Link
                    :href="logout()"
                    method="post"
                    as="button"
                    class="pt-2 text-sm text-zinc-600 hover:text-zinc-950 focus:underline focus:outline-none"
                >
                    Выйти
                </Link>
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
                        placeholder="Полная или короткая ссылка Яндекс Карт"
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

            <div
                v-if="organizations.length"
                class="border-b border-zinc-200 py-6"
            >
                <label for="organization" class="block text-sm font-medium">
                    Организация
                </label>
                <select
                    id="organization"
                    :value="organization?.id"
                    :disabled="deleteRequest.processing"
                    class="mt-2 w-full rounded-md border border-zinc-300 bg-white px-3 py-2.5 text-base outline-none focus:border-zinc-950 focus:ring-2 focus:ring-zinc-950/10"
                    @change="changeOrganization"
                >
                    <option
                        v-for="item in organizations"
                        :key="item.id"
                        :value="item.id"
                    >
                        {{ item.name ?? `Организация ${item.business_id}` }}
                    </option>
                </select>
            </div>

            <section v-if="organization" class="pt-10" aria-live="polite">
                <div class="flex items-start justify-between gap-4">
                    <h2 class="text-xl font-semibold">
                        {{
                            organization.name ??
                            `Организация ${organization.business_id}`
                        }}
                    </h2>
                    <div class="flex items-center gap-4">
                        <p class="text-sm font-medium text-zinc-600">
                            {{ statusLabel }}
                        </p>
                        <button
                            type="button"
                            :disabled="deleteRequest.processing"
                            class="text-sm text-red-700 hover:text-red-900 focus:underline focus:outline-none disabled:cursor-not-allowed disabled:opacity-60"
                            @click="deleteOrganization"
                        >
                            {{
                                deleteRequest.processing
                                    ? 'Удаляем…'
                                    : 'Удалить'
                            }}
                        </button>
                    </div>
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

                <div
                    v-if="organization.sync_status === 'completed'"
                    class="mt-10 border-t border-zinc-200 pt-10"
                >
                    <div
                        class="flex flex-col gap-3 sm:flex-row sm:items-center sm:justify-between"
                    >
                        <h3 class="text-xl font-semibold">Отзывы</h3>
                        <div class="flex items-center gap-3">
                            <p v-if="pagination" class="text-sm text-zinc-500">
                                {{ pagination.total }} найдено
                            </p>
                            <label for="rating" class="sr-only">
                                Фильтр по оценке
                            </label>
                            <select
                                id="rating"
                                v-model="ratingFilter"
                                class="rounded-md border border-zinc-300 bg-white px-3 py-2 text-sm outline-none focus:border-zinc-950 focus:ring-2 focus:ring-zinc-950/10"
                                :disabled="reviewsRequest.processing"
                                @change="loadReviews()"
                            >
                                <option value="">Все оценки</option>
                                <option value="5">5 звёзд</option>
                                <option value="4">4 звезды</option>
                                <option value="3">3 звезды</option>
                                <option value="2">2 звезды</option>
                                <option value="1">1 звезда</option>
                            </select>
                        </div>
                    </div>

                    <p
                        v-if="reviewsRequest.processing && reviews.length === 0"
                        class="mt-6 text-sm text-zinc-600"
                    >
                        Загружаем отзывы…
                    </p>
                    <p
                        v-else-if="reviewsError"
                        class="mt-6 text-sm text-red-700"
                    >
                        {{ reviewsError }}
                    </p>
                    <p
                        v-else-if="
                            !reviewsRequest.processing && reviews.length === 0
                        "
                        class="mt-6 text-sm text-zinc-600"
                    >
                        Отзывов пока нет.
                    </p>

                    <div v-else class="mt-4 divide-y divide-zinc-200">
                        <article
                            v-for="review in reviews"
                            :key="review.id"
                            class="py-6"
                        >
                            <div
                                class="flex flex-col gap-1 sm:flex-row sm:items-baseline sm:justify-between"
                            >
                                <h4 class="font-medium">
                                    {{
                                        review.author_name ??
                                        'Пользователь Яндекса'
                                    }}
                                </h4>
                                <p
                                    class="flex items-center gap-2 text-sm text-zinc-500"
                                >
                                    {{ formatDate(review.published_at) }}
                                    <span
                                        class="inline-flex rounded-full px-2 py-0.5 font-medium ring-1 ring-inset"
                                        :class="
                                            reviewRatingClass(review.rating)
                                        "
                                    >
                                        {{ review.rating }}/5
                                    </span>
                                </p>
                            </div>
                            <p
                                class="mt-3 text-sm leading-6 whitespace-pre-line text-zinc-700"
                            >
                                {{ review.text ?? 'Без текста' }}
                            </p>
                        </article>
                    </div>

                    <nav
                        v-if="pagination && pagination.last_page > 1"
                        class="mt-6 flex flex-wrap gap-2"
                        aria-label="Страницы отзывов"
                    >
                        <button
                            v-for="page in reviewPages"
                            :key="page"
                            type="button"
                            :disabled="
                                reviewsRequest.processing ||
                                page === pagination.current_page
                            "
                            :aria-current="
                                page === pagination.current_page
                                    ? 'page'
                                    : undefined
                            "
                            class="min-w-10 rounded-md border px-3 py-2 text-sm disabled:cursor-default"
                            :class="
                                page === pagination.current_page
                                    ? 'border-zinc-950 bg-zinc-950 text-white'
                                    : 'border-zinc-300 bg-white text-zinc-700 hover:border-zinc-950 hover:text-zinc-950 disabled:opacity-60'
                            "
                            @click="loadReviews(page)"
                        >
                            {{ page }}
                        </button>
                    </nav>
                </div>
            </section>
        </div>
    </main>
</template>
