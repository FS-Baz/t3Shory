<script setup lang="ts">
import { Head } from '@inertiajs/vue3';
import { computed, onBeforeUnmount, ref } from 'vue';

type Verdict = 'GO' | 'DONT';
type Phase = 'idle' | 'judging' | 'done' | 'error';

interface Judgement {
    verdict: Verdict;
    confidence: number;
    probabilities: Record<Verdict, number>;
}

const MIN_LENGTH = 10;
const MAX_LENGTH = 300;
const STEP_MS = 430;

const STATUS_LINES = [
    'تراي حاضر…',
    'أقرأ الفكرة…',
    'أسأل: مين يتعب من هالشي؟',
    'أدور مين بيدفع…',
    'أحسب الوقت والتكلفة…',
    'أفكر بصوت عالي…',
    'قررت.',
];

const VERDICT_TEXT: Record<Verdict, { main: string; sub: string }> = {
    GO: { main: 'امش', sub: 'الفكرة تستاهل' },
    DONT: { main: 'لا تمشي', sub: 'وفّر وقتك' },
};

const idea = ref('');
const phase = ref<Phase>('idle');
const judgement = ref<Judgement | null>(null);
const statusIndex = ref(0);

let timers: number[] = [];

const trimmed = computed(() => idea.value.trim());
const canSubmit = computed(
    () => trimmed.value.length >= MIN_LENGTH && phase.value !== 'judging',
);

const verdictText = computed(() =>
    judgement.value ? VERDICT_TEXT[judgement.value.verdict] : null,
);

const theme = computed(() =>
    judgement.value?.verdict === 'GO'
        ? {
              text: 'text-emerald-400',
              border: 'border-emerald-400/25',
              bg: 'bg-emerald-400/[0.07]',
              bar: 'bg-emerald-400',
          }
        : {
              text: 'text-rose-500',
              border: 'border-rose-500/25',
              bg: 'bg-rose-500/[0.07]',
              bar: 'bg-rose-500',
          },
);

const confidencePct = computed(() =>
    judgement.value ? Math.round(judgement.value.confidence * 100) : 0,
);

const goPct = computed(() =>
    judgement.value ? Math.round((judgement.value.probabilities.GO ?? 0) * 100) : 0,
);

const dontPct = computed(() =>
    judgement.value ? Math.round((judgement.value.probabilities.DONT ?? 0) * 100) : 0,
);

/* ---------- الخطوات ---------- */

function clearTimers(): void {
    timers.forEach((t) => window.clearTimeout(t));
    timers = [];
}

async function submit(): Promise<void> {
    if (!canSubmit.value) return;

    clearTimers();
    phase.value = 'judging';
    judgement.value = null;
    statusIndex.value = 0;

    STATUS_LINES.forEach((_, i) => {
        if (i === 0) return;
        timers.push(window.setTimeout(() => (statusIndex.value = i), i * STEP_MS));
    });

    const minDelay = new Promise((resolve) =>
        window.setTimeout(resolve, STATUS_LINES.length * STEP_MS),
    );

    try {
        const [res] = await Promise.all([
            fetch('/api/submit-idea', {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                    Accept: 'application/json',
                },
                body: JSON.stringify({ idea: trimmed.value }),
            }),
            minDelay,
        ]);

        if (!res.ok) throw new Error(`HTTP ${res.status}`);

        const data = await res.json();
        const answer = data?.answers?.verdict;

        if (
            !answer ||
            (answer.choice !== 'GO' && answer.choice !== 'DONT') ||
            typeof answer.confidence !== 'number'
        ) {
            throw new Error('bad payload');
        }

        judgement.value = {
            verdict: answer.choice,
            confidence: answer.confidence,
            probabilities: {
                GO: answer.probabilities?.GO ?? 0,
                DONT: answer.probabilities?.DONT ?? 0,
            }
        };
        phase.value = 'done';
    } catch {
        phase.value = 'error';
    }
}

function reset(): void {
    clearTimers();
    phase.value = 'idle';
    judgement.value = null;
    idea.value = '';
    statusIndex.value = 0;
}

onBeforeUnmount(clearTimers);
</script>

<template>
    <Head title="طع شوري" />

    <div dir="rtl" class="relative min-h-screen overflow-hidden bg-[#0a0a0c] text-white antialiased">
        <!-- ambient glow -->
        <div
            class="pointer-events-none absolute left-1/2 top-[-14rem] h-[36rem] w-[36rem] -translate-x-1/2 rounded-full bg-white/[0.07] blur-[130px]"
        />
        <!-- grid -->
        <div
            class="pointer-events-none absolute inset-0 opacity-40 [background-image:linear-gradient(to_right,rgba(255,255,255,0.04)_1px,transparent_1px),linear-gradient(to_bottom,rgba(255,255,255,0.04)_1px,transparent_1px)] [background-size:56px_56px] [mask-image:radial-gradient(ellipse_at_center,black,transparent_72%)]"
        />

        <main
            class="relative mx-auto flex min-h-screen w-full max-w-2xl flex-col justify-center gap-8 px-5 py-14"
        >
            <!-- header -->
            <header class="text-center">
                <span
                    class="inline-flex items-center gap-2 rounded-full border border-white/10 bg-white/[0.04] px-3 py-1 text-[11px] text-white/55"
                >
                    <span class="h-1.5 w-1.5 animate-pulse rounded-full bg-emerald-400" />
                    تراي حاضر ومستعد
                </span>

                <h1 class="mt-4 text-6xl font-bold tracking-tight sm:text-7xl">طع شوري</h1>

                <p class="mt-3 text-sm leading-relaxed text-white/45">
                    اكتب فكرتك بجملة أو جملتين، وأنا أقول لك رأيي.<br />
                    بدون مجاملات، وبدون لف ودوران.
                </p>
            </header>

            <!-- 1. input -->
            <section
                v-if="phase === 'idle'"
                class="rounded-3xl border border-white/10 bg-white/[0.03] p-4 backdrop-blur-sm sm:p-5"
            >
                <textarea
                    v-model="idea"
                    :maxlength="MAX_LENGTH"
                    rows="4"
                    dir="rtl"
                    placeholder="فكرتي هي…"
                    class="w-full resize-none bg-transparent text-lg leading-relaxed text-white placeholder:text-white/25 focus:outline-none"
                    @keydown.ctrl.enter="submit"
                    @keydown.meta.enter="submit"
                />

                <div class="mt-3 flex items-center justify-between gap-3 border-t border-white/5 pt-3">
                    <span class="text-[11px] tabular-nums text-white/30">
                        {{ trimmed.length }} / {{ MAX_LENGTH }}
                    </span>

                    <button
                        type="button"
                        :disabled="!canSubmit"
                        class="rounded-full bg-white px-5 py-2.5 text-sm font-semibold text-black transition hover:bg-white/90 disabled:cursor-not-allowed disabled:bg-white/10 disabled:text-white/30"
                        @click="submit"
                    >
                        شاورني
                    </button>
                </div>
            </section>

            <!-- 2. judging -->
            <section
                v-else-if="phase === 'judging'"
                class="flex flex-col items-center gap-5 rounded-3xl border border-white/10 bg-white/[0.03] px-6 py-14 text-center"
            >
                <div class="flex gap-1.5">
                    <span
                        v-for="n in 3"
                        :key="n"
                        class="h-2 w-2 animate-bounce rounded-full bg-white/40"
                        :style="{ animationDelay: `${(n - 1) * 130}ms` }"
                    />
                </div>
                <p class="text-sm text-white/60 transition-all duration-200">
                    {{ STATUS_LINES[statusIndex] }}
                </p>
            </section>

            <!-- 3. verdict -->
            <section
                v-else-if="phase === 'done' && judgement && verdictText"
                class="fade-up rounded-3xl border p-8 text-center sm:p-10"
                :class="[theme.border, theme.bg]"
            >
                <p class="text-[11px] uppercase tracking-[0.35em] text-white/35">شوري عليك</p>

                <p class="stamp mt-4 text-7xl font-black tracking-tight sm:text-8xl" :class="theme.text">
                    {{ verdictText.main }}
                </p>
                <p class="mt-3 text-lg font-semibold text-white/80">
                    {{ verdictText.sub }}
                </p>

                <!-- confidence -->
                <div class="mx-auto mt-8 max-w-xs">
                    <div class="mb-2 flex items-center justify-between text-[11px] text-white/45">
                        <span>قناعتي بالحكم</span>
                        <span class="tabular-nums">{{ confidencePct }}%</span>
                    </div>
                    <div class="h-1.5 w-full overflow-hidden rounded-full bg-white/10">
                        <div
                            class="h-full rounded-full transition-all duration-700 ease-out"
                            :class="theme.bar"
                            :style="{ width: `${confidencePct}%` }"
                        />
                    </div>
                </div>

                <!-- probabilities -->
                <div class="mx-auto mt-6 grid max-w-xs grid-cols-2 gap-3">
                    <div
                        class="rounded-2xl border border-emerald-400/20 bg-emerald-400/[0.06] px-3 py-3"
                        :class="{ 'opacity-40': judgement.verdict !== 'GO' }"
                    >
                        <p class="text-[11px] text-emerald-400/80">امش</p>
                        <p class="mt-1 text-xl font-bold tabular-nums text-emerald-400">
                            {{ goPct }}%
                        </p>
                    </div>

                    <div
                        class="rounded-2xl border border-rose-500/20 bg-rose-500/[0.06] px-3 py-3"
                        :class="{ 'opacity-40': judgement.verdict !== 'DONT' }"
                    >
                        <p class="text-[11px] text-rose-500/80">لا تمشي</p>
                        <p class="mt-1 text-xl font-bold tabular-nums text-rose-500">
                            {{ dontPct }}%
                        </p>
                    </div>
                </div>

                <p class="mt-6 truncate text-xs text-white/25" :title="trimmed">
                    «{{ trimmed }}»
                </p>

                <button
                    type="button"
                    class="mt-6 rounded-full border border-white/15 px-5 py-2.5 text-sm text-white/70 transition hover:border-white/30 hover:text-white"
                    @click="reset"
                >
                    فكرة ثانية
                </button>
            </section>

            <!-- 4. error -->
            <section
                v-else-if="phase === 'error'"
                class="fade-up rounded-3xl border border-white/10 bg-white/[0.03] p-10 text-center"
            >
                <p class="text-sm text-white/60">ما قدرت أوصل… جرّب مرة ثانية.</p>

                <button
                    type="button"
                    class="mt-6 rounded-full border border-white/15 px-5 py-2.5 text-sm text-white/70 transition hover:border-white/30 hover:text-white"
                    @click="submit"
                >
                    جرّب مرة ثانية
                </button>
            </section>

            <p class="text-center text-[11px] text-white/20">
                طع شوري · الشور شوري، والقرار عندك
            </p>
        </main>
    </div>
</template>

<style scoped>
.stamp {
    animation: stamp-in 460ms cubic-bezier(0.2, 0.9, 0.25, 1.35) both;
}

@keyframes stamp-in {
    from {
        opacity: 0;
        transform: scale(1.9) rotate(-9deg);
        filter: blur(8px);
    }
    to {
        opacity: 1;
        transform: scale(1) rotate(-3deg);
        filter: blur(0);
    }
}

.fade-up {
    animation: fade-up 380ms ease-out both;
}

@keyframes fade-up {
    from {
        opacity: 0;
        transform: translateY(10px);
    }
    to {
        opacity: 1;
        transform: translateY(0);
    }
}
</style>