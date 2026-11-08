<script setup lang="ts">
import { Head } from '@inertiajs/vue3';
import { computed, onBeforeUnmount, ref } from 'vue';

type Verdict = 'GO' | 'DONT';
type Phase = 'idle' | 'judging' | 'done' | 'error';
type AssessmentChoice = 'STRONG' | 'MODERATE' | 'WEAK' | 'HIGH' | 'MEDIUM' | 'LOW';
type Tone = 'good' | 'warn' | 'bad';

interface ChoiceAnswer {
    choice: string;
    confidence?: number;
    probabilities?: Record<string, number>;
}

interface Judgement {
    verdict: Verdict;
    confidence: number;
    probabilities: Record<Verdict, number>;
    assessments: {
        problem: ChoiceAnswer;
        demand: ChoiceAnswer;
        feasibility: ChoiceAnswer;
        competition: ChoiceAnswer;
        financial_viability: ChoiceAnswer;
        risk: ChoiceAnswer;
    };
}

const MAX_LENGTH = 300;
const STEP_MS = 430;

const STATUS_LINES = [
    'تراي حاضر…',
    'أقرأ الفكرة…',
    'أفحص المشكلة اللي تحلها…',
    'أشوف مين ممكن يحتاجها…',
    'أقيّم إمكانية تنفيذها…',
    'أفكر بالمنافسين والتكلفة…',
    'أوازن المخاطر…',
    'قررت.',
];

const VERDICT_TEXT: Record<Verdict, { main: string; sub: string }> = {
    GO: { main: 'امش', sub: 'الفكرة تستاهل' },
    DONT: { main: 'لا تمشي', sub: 'وفّر وقتك' },
};

const ASSESSMENT_LABELS = [
    { key: 'problem', label: 'الحاجة', description: 'هل هذا الشيء فعلاً مفيد أو له داعي؟' },
    { key: 'demand', label: 'الفائدة', description: 'هل النتيجة المتوقعة تستاهل؟' },
    { key: 'feasibility', label: 'التنفيذ', description: 'هل تقدر تسويه على أرض الواقع؟' },
    { key: 'competition', label: 'البدائل', description: 'وش راح تخسر مقابل هالقرار؟' },
    { key: 'financial_viability', label: 'التكلفة والعائد', description: 'هل يستاهل الوقت أو الجهد أو الفلوس؟' },
    { key: 'risk', label: 'المخاطر', description: 'وش أسوأ شيء ممكن يصير؟' },
] as const;

const CHOICE_LABELS: Record<string, string> = {
    STRONG: 'قوي',
    MODERATE: 'متوسط',
    WEAK: 'ضعيف',
    HIGH: 'مرتفع',
    MEDIUM: 'متوسط',
    LOW: 'منخفض',
};

const TONE_CLASSES: Record<Tone, { badge: string; dot: string }> = {
    good: { badge: 'bg-emerald-500/15 text-emerald-400 border-emerald-500/30', dot: 'bg-emerald-400' },
    warn: { badge: 'bg-amber-500/15 text-amber-400 border-amber-500/30', dot: 'bg-amber-400' },
    bad: { badge: 'bg-rose-500/15 text-rose-400 border-rose-500/30', dot: 'bg-rose-400' },
};

const idea = ref('');
const phase = ref<Phase>('idle');
const judgement = ref<Judgement | null>(null);
const statusIndex = ref(0);

let timers: number[] = [];

const trimmed = computed(() => idea.value.trim());
const canSubmit = computed(() => trimmed.value.length > 0 && phase.value !== 'judging');
const verdictText = computed(() =>
    judgement.value ? VERDICT_TEXT[judgement.value.verdict] : null,
);

const theme = computed(() =>
    judgement.value?.verdict === 'GO'
        ? {
              text: 'text-emerald-400 drop-shadow-[0_0_24px_rgba(52,211,153,0.35)]',
              border: 'border-emerald-400/25',
              bg: 'bg-emerald-400/[0.05]',
              bar: 'bg-emerald-400 shadow-[0_0_14px_-2px_rgba(52,211,153,0.9)]',
              glow: 'bg-emerald-400/20',
          }
        : {
              text: 'text-rose-500 drop-shadow-[0_0_24px_rgba(244,63,94,0.35)]',
              border: 'border-rose-500/25',
              bg: 'bg-rose-500/[0.05]',
              bar: 'bg-rose-500 shadow-[0_0_14px_-2px_rgba(244,63,94,0.9)]',
              glow: 'bg-rose-500/20',
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

function choiceTone(key: string, choice: string): Tone {
    // Risk gets inverted colors
    if (key === 'risk') {
        if (choice === 'HIGH') return 'bad';   // High risk = Red
        if (choice === 'MEDIUM') return 'warn'; // Medium risk = Amber
        if (choice === 'LOW') return 'good';   // Low risk = Green
        return 'warn';
    }
    
    // Other metrics
    if (choice === 'STRONG') return 'good'; // Strong = Green
    if (choice === 'HIGH') return 'warn';   // High demand/competition = Amber (instead of green)
    if (choice === 'WEAK') return 'bad';    // Weak = Red
    if (choice === 'LOW') return 'bad';     // Low = Red
    return 'warn';                          // Moderate/Medium = Amber
}

function clearTimers(): void {
    timers.forEach((timer) => window.clearTimeout(timer));
    timers = [];
}

function isChoiceAnswer(value: unknown): value is ChoiceAnswer {
    return (
        typeof value === 'object' &&
        value !== null &&
        typeof (value as ChoiceAnswer).choice === 'string'
    );
}

function submit(): void {
    if (!canSubmit.value) return;

    clearTimers();
    phase.value = 'judging';
    judgement.value = null;
    statusIndex.value = 0;

    STATUS_LINES.forEach((_, index) => {
        if (index === 0) return;
        timers.push(
            window.setTimeout(() => {
                statusIndex.value = index;
            }, index * STEP_MS),
        );
    });

    const minDelay = new Promise<void>((resolve) => {
        window.setTimeout(resolve, STATUS_LINES.length * STEP_MS);
    });

    void (async () => {
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
            const answers = data?.answers;
            const verdict = answers?.verdict;

            const assessmentKeys = [
                'problem',
                'demand',
                'feasibility',
                'competition',
                'financial_viability',
                'risk',
            ] as const;

            if (
                !verdict ||
                (verdict.choice !== 'GO' && verdict.choice !== 'DONT') ||
                typeof verdict.confidence !== 'number' ||
                !assessmentKeys.every((key) => isChoiceAnswer(answers?.[key]))
            ) {
                throw new Error('Invalid evaluation response');
            }

            judgement.value = {
                verdict: verdict.choice,
                confidence: verdict.confidence,
                probabilities: {
                    GO: verdict.probabilities?.GO ?? 0,
                    DONT: verdict.probabilities?.DONT ?? 0,
                },
                assessments: {
                    problem: answers.problem,
                    demand: answers.demand,
                    feasibility: answers.feasibility,
                    competition: answers.competition,
                    financial_viability: answers.financial_viability,
                    risk: answers.risk,
                },
            };

            phase.value = 'done';
        } catch {
            phase.value = 'error';
        }
    })();
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

    <div dir="rtl" class="relative min-h-screen overflow-hidden bg-[#08080a] text-white antialiased">
        <!-- Ambient -->
        <div class="pointer-events-none absolute inset-0 overflow-hidden">
            <div class="absolute left-1/2 top-[-18rem] h-[40rem] w-[40rem] -translate-x-1/2 rounded-full bg-white/[0.055] blur-[140px]" />
            <div class="absolute -left-40 top-1/3 h-[28rem] w-[28rem] rounded-full bg-emerald-500/[0.035] blur-[120px]" />
            <div class="absolute -right-40 bottom-0 h-[28rem] w-[28rem] rounded-full bg-rose-500/[0.035] blur-[120px]" />
        </div>

        <!-- Grid -->
        <div class="pointer-events-none absolute inset-0 opacity-[0.35] [background-image:linear-gradient(to_right,rgba(255,255,255,0.035)_1px,transparent_1px),linear-gradient(to_bottom,rgba(255,255,255,0.035)_1px,transparent_1px)] [background-size:56px_56px] [mask-image:radial-gradient(ellipse_at_center,black,transparent_75%)]" />

        <main class="relative mx-auto flex min-h-screen w-full max-w-2xl flex-col justify-center gap-8 px-5 py-16">
            <!-- Header -->
            <header class="text-center">
                <span class="inline-flex items-center gap-2.5 rounded-full border border-white/[0.08] bg-white/[0.03] px-3.5 py-1.5 text-[11px] font-medium text-white/55 backdrop-blur-sm">
                    <span class="relative flex h-1.5 w-1.5">
                        <span class="absolute inline-flex h-full w-full animate-ping rounded-full bg-emerald-400/60" />
                        <span class="relative inline-flex h-1.5 w-1.5 rounded-full bg-emerald-400" />
                    </span>
                    تراي حاضر ومستعد
                </span>

                <h1 class="mt-6 bg-gradient-to-b from-white via-white to-white/50 bg-clip-text text-6xl font-bold leading-[1.3] text-transparent sm:text-7xl">
                    طع شوري
                </h1>

                <p class="mx-auto mt-4 max-w-sm text-sm leading-[1.9] text-white/40">
                    اكتب فكرتك بجملة أو جملتين، وأنا أقول لك رأيي.<br />
                    بدون مجاملات، وبدون لف ودوران.
                </p>
            </header>

            <!-- Input -->
            <section v-if="phase === 'idle'" class="fade-up group relative rounded-3xl">
                <div class="pointer-events-none absolute inset-0 rounded-3xl bg-gradient-to-b from-white/[0.15] via-white/[0.05] to-white/[0.02]" />
                <div class="pointer-events-none absolute -inset-1 rounded-3xl bg-gradient-to-br from-emerald-400/15 via-transparent to-rose-400/15 opacity-0 blur-xl transition-opacity duration-500 group-focus-within:opacity-100" />

                <div class="relative m-px rounded-[calc(1.5rem-1px)] bg-[#0c0c0f] p-5 sm:p-6">
                    <textarea
                        v-model="idea"
                        :maxlength="MAX_LENGTH"
                        rows="4"
                        dir="rtl"
                        placeholder="فكرتي هي…"
                        class="w-full resize-none bg-transparent text-lg leading-[1.9] text-white placeholder:text-white/25 focus:outline-none"
                        @keydown.ctrl.enter="submit"
                        @keydown.meta.enter="submit"
                    />

                    <div class="mt-4 flex items-center justify-between gap-3 border-t border-white/[0.06] pt-4">
                        <span class="text-[11px] tabular-nums text-white/30">
                            {{ trimmed.length }} / {{ MAX_LENGTH }}
                        </span>

                        <button
                            type="button"
                            :disabled="!canSubmit"
                            class="rounded-full bg-gradient-to-b from-white to-white/85 px-6 py-2.5 text-sm font-semibold text-black shadow-[0_4px_20px_-4px_rgba(255,255,255,0.4)] transition-all duration-200 hover:from-white hover:to-white hover:shadow-[0_6px_28px_-4px_rgba(255,255,255,0.55)] active:scale-[0.98] disabled:cursor-not-allowed disabled:from-white/10 disabled:to-white/5 disabled:text-white/30 disabled:shadow-none"
                            @click="submit"
                        >
                            شاورني
                        </button>
                    </div>
                </div>
            </section>

            <!-- Judging -->
            <section
                v-else-if="phase === 'judging'"
                class="fade-up flex flex-col items-center gap-8 rounded-3xl border border-white/[0.08] bg-white/[0.02] px-6 py-14 text-center"
            >
                <div class="flex w-full max-w-[240px] gap-1">
                    <div
                        v-for="(_, i) in STATUS_LINES"
                        :key="i"
                        class="h-[3px] flex-1 overflow-hidden rounded-full bg-white/10"
                    >
                        <div
                            class="h-full origin-right rounded-full bg-white transition-transform duration-500 ease-out"
                            :class="i <= statusIndex ? 'scale-x-100' : 'scale-x-0'"
                        />
                    </div>
                </div>

                <p class="min-h-[1.5em] text-base font-medium text-white/70 transition-all duration-300">
                    {{ STATUS_LINES[statusIndex] }}
                </p>
            </section>

            <!-- Done -->
            <section v-else-if="phase === 'done' && judgement && verdictText" class="space-y-5">
                <div
                    class="fade-up relative overflow-hidden rounded-3xl border p-8 text-center sm:p-10"
                    :class="[theme.border, theme.bg]"
                >
                    <div class="pointer-events-none absolute left-1/2 top-1/2 h-[20rem] w-[20rem] -translate-x-1/2 -translate-y-1/2 rounded-full blur-[80px]" :class="theme.glow" />

                    <p class="relative text-[11px] font-medium text-white/35">شوري عليك</p>

                    <p class="stamp relative mt-4 text-7xl font-black leading-none sm:text-8xl" :class="theme.text">
                        {{ verdictText.main }}
                    </p>
                    <p class="relative mt-4 text-base font-medium text-white/70">
                        {{ verdictText.sub }}
                    </p>

                    <div class="relative mx-auto mt-9 max-w-xs">
                        <div class="mb-2.5 flex items-center justify-between text-[11px] text-white/45">
                            <span>قناعتي بالحكم</span>
                            <span class="font-semibold tabular-nums text-white/70">{{ confidencePct }}%</span>
                        </div>
                        <div class="h-1.5 w-full overflow-hidden rounded-full bg-white/[0.07]">
                            <div
                                class="confidence-bar h-full rounded-full"
                                :class="theme.bar"
                                :style="{ '--w': confidencePct + '%' }"
                            />
                        </div>
                    </div>

                    <div class="relative mx-auto mt-7 grid max-w-xs grid-cols-2 gap-3">
                        <div
                            class="rounded-2xl border border-emerald-400/20 bg-emerald-400/[0.05] px-3.5 py-3.5 text-right transition-opacity"
                            :class="{ 'opacity-35': judgement.verdict !== 'GO' }"
                        >
                            <p class="text-[11px] font-medium text-emerald-400/70">امش</p>
                            <p class="mt-1 text-xl font-bold tabular-nums text-emerald-400">{{ goPct }}%</p>
                        </div>

                        <div
                            class="rounded-2xl border border-rose-500/20 bg-rose-500/[0.05] px-3.5 py-3.5 text-right transition-opacity"
                            :class="{ 'opacity-35': judgement.verdict !== 'DONT' }"
                        >
                            <p class="text-[11px] font-medium text-rose-500/70">لا تمشي</p>
                            <p class="mt-1 text-xl font-bold tabular-nums text-rose-500">{{ dontPct }}%</p>
                        </div>
                    </div>

                    <p class="relative mt-7 truncate text-xs text-white/25" :title="trimmed">
                        «{{ trimmed }}»
                    </p>
                </div>

                <div class="fade-up rounded-3xl border border-white/[0.08] bg-white/[0.02] p-5 sm:p-6" style="animation-delay: 80ms">
                    <div class="mb-5">
                        <h2 class="text-lg font-bold text-white/90">تفاصيل الحكم</h2>
                        <p class="mt-1 text-xs text-white/40">
                            كيف قيّم شوري فكرتك من جوانب مختلفة
                        </p>
                    </div>

                    <div class="grid gap-3 sm:grid-cols-2">
                        <div
                            v-for="assessment in ASSESSMENT_LABELS"
                            :key="assessment.key"
                            class="group rounded-2xl border border-white/[0.06] bg-white/[0.02] p-4 transition-colors hover:border-white/[0.12] hover:bg-white/[0.03]"
                        >
                            <div class="flex items-center justify-between gap-3">
                                <h3 class="text-sm font-semibold text-white/85">{{ assessment.label }}</h3>
                                <span
                                    class="inline-flex items-center gap-1.5 rounded-full border px-2.5 py-0.5 text-[11px] font-semibold"
                                    :class="TONE_CLASSES[choiceTone(assessment.key, judgement.assessments[assessment.key].choice)].badge"
                                >
                                    <span class="h-1 w-1 rounded-full" :class="TONE_CLASSES[choiceTone(assessment.key, judgement.assessments[assessment.key].choice)].dot" />
                                    {{ CHOICE_LABELS[judgement.assessments[assessment.key].choice] ?? judgement.assessments[assessment.key].choice }}
                                </span>
                            </div>
                            <p class="mt-2 text-xs leading-relaxed text-white/35">
                                {{ assessment.description }}
                            </p>
                        </div>
                    </div>
                </div>

                <div class="fade-up flex justify-center" style="animation-delay: 140ms">
                    <button
                        type="button"
                        class="rounded-full border border-white/10 bg-white/[0.02] px-5 py-2.5 text-sm text-white/60 transition-all duration-200 hover:border-white/25 hover:bg-white/[0.05] hover:text-white active:scale-[0.98]"
                        @click="reset"
                    >
                        فكرة ثانية
                    </button>
                </div>
            </section>

            <!-- Error -->
            <section
                v-else-if="phase === 'error'"
                class="fade-up rounded-3xl border border-white/[0.08] bg-white/[0.02] p-10 text-center"
            >
                <p class="text-sm text-white/60">ما قدرت أوصل… جرّب مرة ثانية.</p>

                <button
                    type="button"
                    class="mt-6 rounded-full border border-white/10 bg-white/[0.02] px-5 py-2.5 text-sm text-white/60 transition-all duration-200 hover:border-white/25 hover:bg-white/[0.05] hover:text-white active:scale-[0.98]"
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
    animation: stamp-in 520ms cubic-bezier(0.2, 0.9, 0.25, 1.35) both;
    animation-delay: 60ms;
}

@keyframes stamp-in {
    from {
        opacity: 0;
        transform: scale(1.8) rotate(-8deg);
        filter: blur(10px);
    }
    to {
        opacity: 1;
        transform: scale(1) rotate(-3deg);
        filter: blur(0);
    }
}

.fade-up {
    animation: fade-up 500ms cubic-bezier(0.2, 0.9, 0.25, 1) both;
}

@keyframes fade-up {
    from {
        opacity: 0;
        transform: translateY(14px);
    }
    to {
        opacity: 1;
        transform: translateY(0);
    }
}

.confidence-bar {
    animation: grow-bar 1000ms cubic-bezier(0.2, 0.9, 0.25, 1) both;
    animation-delay: 120ms;
}

@keyframes grow-bar {
    from {
        width: 0;
    }
    to {
        width: var(--w);
    }
}
</style>