<?php

namespace App\Http\Controllers;

use App\Models\Idea;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;

class IdeaController extends Controller
{


    public function submit(Request $request): JsonResponse
    {
        $request->validate([
            'idea' => ['required', 'string', 'max:300'],
        ]);

        $response = Http::withToken(config('ai.providers.jev.key'))
            ->post(config('ai.providers.jev.url'), [
                'state' => $request->input('idea'),
                'model' => 'jev-latest',
                'questions' => [
                    'problem' => [
                        'type' => 'choice',
                        'instructions' => 'Evaluate the core need, problem, or motivation behind the user\'s question. Is this a genuine need, a good idea, or something that solves a real issue for them or others?',
                        'criteria' => [
                            'STRONG' => 'Addresses a very clear, genuine, and valuable need or goal.',
                            'MODERATE' => 'Has some value, but the need or motivation is not entirely clear or critical.',
                            'WEAK' => 'Lacks a real need, is unnecessary, or is driven by impulse/confusion.',
                        ],
                    ],

                    'demand' => [
                        'type' => 'choice',
                        'instructions' => 'Evaluate the likelihood that this decision will yield a positive outcome, benefit, or practical value. Consider the broader context and potential impact.',
                        'criteria' => [
                            'HIGH' => 'Highly likely to produce a positive, useful, or necessary outcome.',
                            'MEDIUM' => 'Might produce a positive outcome, but it is uncertain or depends on other factors.',
                            'LOW' => 'Unlikely to produce a positive outcome or benefit.',
                        ],
                    ],

                    'feasibility' => [
                        'type' => 'choice',
                        'instructions' => 'Evaluate whether the user can realistically execute this decision given normal circumstances, time, resources, and practical constraints.',
                        'criteria' => [
                            'HIGH' => 'Very practical and easy to execute with available resources.',
                            'MEDIUM' => 'Executable, but requires effort, planning, or certain conditions to be met.',
                            'LOW' => 'Impractical, extremely difficult, or impossible to execute realistically.',
                        ],
                    ],

                    'competition' => [
                        'type' => 'choice',
                        'instructions' => 'Evaluate the potential drawbacks, competing priorities, or negative trade-offs of making this choice. What is the user sacrificing or risking by doing this?',
                        'criteria' => [
                            'STRONG' => 'Minimal drawbacks, high upside, and a clear advantage over the alternative.',
                            'MODERATE' => 'Has some drawbacks or trade-offs, but they are manageable.',
                            'WEAK' => 'High downside, major trade-offs, or a clearly better alternative exists.',
                        ],
                    ],

                    'financial_viability' => [
                        'type' => 'choice',
                        'instructions' => 'Evaluate the practical or resource impact of this decision (e.g., time, money, energy, or long-term sustainability).',
                        'criteria' => [
                            'HIGH' => 'Highly sustainable and a very wise use of time/money/energy.',
                            'MEDIUM' => 'Sustainable or break-even, but requires careful management.',
                            'LOW' => 'A waste of time/money/energy or highly unsustainable.',
                        ],
                    ],

                    'risk' => [
                        'type' => 'choice',
                        'instructions' => 'Assess the overall risk and potential negative consequences of this decision.',
                        'criteria' => [
                            'HIGH' => 'Faces significant risks or potential negative consequences.',
                            'MEDIUM' => 'Faces manageable risks that require some caution.',
                            'LOW' => 'Has relatively few risks and is safe to proceed with.',
                        ],
                    ],

                    'verdict' => [
                        'type' => 'choice',
                        'instructions' => 'Make a final, independent, and common-sense judgment. Should the user go ahead with this? Weigh the practical value, feasibility, and risks. Be honest, direct, and realistic. Do not be overly negative, but do not give false hope.',
                        'criteria' => [
                            'GO' => 'The decision is wise, beneficial, or reasonable enough to justify doing it.',
                            'DONT' => 'The decision is unwise, risky, or not worth the effort.',
                        ],
                    ],
                ],
            ])
            ->throw()
            ->json();

        $answers = $response['answers'] ?? [];

        $requiredQuestions = [
            'problem',
            'demand',
            'feasibility',
            'competition',
            'financial_viability',
            'risk',
            'verdict',
        ];

        foreach ($requiredQuestions as $question) {
            if (
                ! isset($answers[$question]['choice']) ||
                ! is_string($answers[$question]['choice'])
            ) {
                return response()->json([
                    'message' => "Missing or invalid upstream answer: {$question}",
                ], 502);
            }
        }

        $answer = $answers['verdict'];

        if (! in_array($answer['choice'], ['GO', 'DONT'], true)) {
            return response()->json([
                'message' => 'Invalid upstream verdict',
            ], 502);
        }

        $toPct = fn($value) => (int) round(((float) $value) * 100);

        $confidence = $toPct($answer['confidence'] ?? 0);
        $goProbability = $toPct($answer['probabilities']['GO'] ?? 0);
        $dontProbability = $toPct($answer['probabilities']['DONT'] ?? 0);

        $model = $response['model'] ?? 'jev-latest';

        Idea::create([
            'idea' => $request->input('idea'),
            'verdict' => $answer['choice'],
            'confidence' => $confidence,
            'go_probability' => $goProbability,
            'dont_probability' => $dontProbability,
            'model' => $model,
        ]);

        $formattedAnswers = [];

        foreach ($requiredQuestions as $question) {
            $formattedAnswers[$question] = [
                'type' => 'choice',
                'choice' => $answers[$question]['choice'],
            ];

            if ($question === 'verdict') {
                $formattedAnswers[$question]['confidence'] = $confidence / 100;

                $formattedAnswers[$question]['probabilities'] = [
                    'GO' => $goProbability / 100,
                    'DONT' => $dontProbability / 100,
                ];
            }
        }

        return response()->json([
            'model' => $model,
            'answers' => $formattedAnswers,
        ]);
    }
}
