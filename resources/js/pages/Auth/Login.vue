<script setup lang="ts">
import { store } from '@/actions/App/Http/Controllers/Auth/AuthenticatedSessionController';
import { Form, Head } from '@inertiajs/vue3';
</script>

<template>
    <Head title="Вход" />

    <main class="min-h-screen bg-zinc-50 px-5 py-16 text-zinc-950 sm:py-24">
        <div class="mx-auto max-w-sm">
            <header class="mb-8">
                <h1 class="text-3xl font-semibold tracking-tight">Вход</h1>
                <p class="mt-3 text-sm leading-6 text-zinc-600">
                    Тестовый пользователь: test@example.com / password
                </p>
            </header>

            <Form
                v-bind="store.form()"
                class="flex flex-col gap-5"
                #default="{ errors, processing }"
            >
                <div>
                    <label for="email" class="block text-sm font-medium">
                        Email
                    </label>
                    <input
                        id="email"
                        type="email"
                        name="email"
                        value="test@example.com"
                        required
                        autofocus
                        autocomplete="username"
                        class="mt-2 w-full rounded-md border border-zinc-300 bg-white px-3 py-2.5 text-base outline-none focus:border-zinc-950 focus:ring-2 focus:ring-zinc-950/10"
                        :aria-invalid="errors.email ? 'true' : undefined"
                        aria-describedby="email-error"
                    />
                    <p
                        v-if="errors.email"
                        id="email-error"
                        class="mt-2 text-sm text-red-700"
                    >
                        {{ errors.email }}
                    </p>
                </div>

                <div>
                    <label for="password" class="block text-sm font-medium">
                        Пароль
                    </label>
                    <input
                        id="password"
                        type="password"
                        name="password"
                        required
                        autocomplete="current-password"
                        class="mt-2 w-full rounded-md border border-zinc-300 bg-white px-3 py-2.5 text-base outline-none focus:border-zinc-950 focus:ring-2 focus:ring-zinc-950/10"
                        :aria-invalid="errors.password ? 'true' : undefined"
                        aria-describedby="password-error"
                    />
                    <p
                        v-if="errors.password"
                        id="password-error"
                        class="mt-2 text-sm text-red-700"
                    >
                        {{ errors.password }}
                    </p>
                </div>

                <button
                    type="submit"
                    :disabled="processing"
                    class="rounded-md bg-zinc-950 px-5 py-2.5 text-sm font-medium text-white hover:bg-zinc-800 focus:ring-2 focus:ring-zinc-950 focus:ring-offset-2 focus:outline-none disabled:cursor-not-allowed disabled:opacity-60"
                >
                    {{ processing ? 'Входим…' : 'Войти' }}
                </button>
            </Form>
        </div>
    </main>
</template>
