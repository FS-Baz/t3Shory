<?php

use Illuminate\Support\Facades\Http;

it('accepts an idea shorter than ten characters', function () {
    config()->set('ai.providers.jev.url', 'https://jev.test/system');

    Http::preventStrayRequests();
    Http::fake([
        'https://jev.test/system' => Http::response([
            'model' => 'jev-test',
            'answers' => [
                'verdict' => [
                    'choice' => 'GO',
                    'confidence' => 0.8,
                    'probabilities' => [
                        'GO' => 0.8,
                        'DONT' => 0.2,
                    ],
                ],
            ],
        ]),
    ]);

    $response = $this->postJson('/api/submit-idea', ['idea' => 'Idea']);

    $response->assertOk()
        ->assertJsonPath('answers.verdict.choice', 'GO');

    $this->assertDatabaseHas('ideas', [
        'idea' => 'Idea',
        'verdict' => 'GO',
    ]);
});
