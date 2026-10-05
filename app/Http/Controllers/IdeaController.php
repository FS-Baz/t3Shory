<?php

namespace App\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;
use App\Models\Idea;

class IdeaController extends Controller
{
    public function submit(Request $request): JsonResponse
    {
        $request->validate([
            'idea' => ['required', 'string', 'min:10', 'max:300'],
        ]);

        $response = Http::withToken(config('ai.providers.jev.key'))
            ->post(config('ai.providers.jev.url'), [
                'state' => $request->input('idea'),
                'model' => 'jev-latest',
                'questions' => [
                    'verdict' => [
                        'type' => 'choice',
                        'instructions' => 'Should this idea be pursued?',
                        'criteria' => [
                            'GO' => 'The idea is worth pursuing.',
                            'DONT' => 'The idea is not worth pursuing.',
                        ],
                    ],
                ],
            ])
            ->throw()
            ->json();

        $answer = $response['answers']['verdict'] ?? null;

        if (! $answer || ! in_array($answer['choice'] ?? null, ['GO', 'DONT'], true)) {
            return response()->json(['message' => 'bad upstream payload'], 502);
        }

        // 0.99 → 99, 0.01 → 1
        $toPct = fn($v) => (int) round(((float) $v) * 100);

        $confidence       = $toPct($answer['confidence'] ?? 0);
        $goProbability    = $toPct($answer['probabilities']['GO'] ?? 0);
        $dontProbability  = $toPct($answer['probabilities']['DONT'] ?? 0);
        $model            = $response['model'] ?? 'jev-latest';

        Idea::create([
            'idea'             => $request->input('idea'),
            'verdict'          => $answer['choice'],
            'confidence'       => $confidence,
            'go_probability'   => $goProbability,
            'dont_probability' => $dontProbability,
            'model'            => $model,
        ]);

        return response()->json([
            'model'   => $model,
            'answers' => [
                'verdict' => [
                    'type'          => 'choice',
                    'choice'        => $answer['choice'],
                    'confidence'    => $confidence / 100,      // back to 0–1 for the frontend
                    'probabilities' => [
                        'GO'   => $goProbability / 100,
                        'DONT' => $dontProbability / 100,
                    ],
                ],
            ],
        ]);
    }
}
