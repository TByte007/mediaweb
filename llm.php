<?php

/**
 * Optional llama-server client for display-title cleanup.
 * Disabled when MW_LLM_URL is empty.
 */

declare(strict_types=1);

function mwLlmEnabled(): bool
{
    return defined('MW_LLM_URL') && MW_LLM_URL !== '';
}

function mwLlmAvailable(): bool
{
    if (!mwLlmEnabled()) return false;
    $ch = curl_init(rtrim(MW_LLM_URL, '/') . '/health');
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => 3,
        CURLOPT_CONNECTTIMEOUT => 2,
    ]);
    curl_exec($ch);
    $ok = curl_errno($ch) === 0 && (int)curl_getinfo($ch, CURLINFO_HTTP_CODE) === 200;
    curl_close($ch);
    return $ok;
}

/** One-shot chat completion; null on failure. Thinking disabled. */
function mwLlmChat(string $system, string $user): ?string
{
    if (!mwLlmEnabled()) return null;
    $payload = [
        'messages' => [
            ['role' => 'system', 'content' => $system],
            ['role' => 'user', 'content' => $user],
        ],
        'temperature' => 0.1,
        'max_tokens' => 64,
        'stream' => false,
        'chat_template_kwargs' => ['enable_thinking' => false],
    ];
    if (defined('MW_LLM_MODEL') && MW_LLM_MODEL !== '')
        $payload['model'] = MW_LLM_MODEL;

    $ch = curl_init(rtrim(MW_LLM_URL, '/') . '/v1/chat/completions');
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST => true,
        CURLOPT_HTTPHEADER => ['Content-Type: application/json'],
        CURLOPT_POSTFIELDS => json_encode($payload, JSON_UNESCAPED_UNICODE),
        CURLOPT_TIMEOUT => defined('MW_LLM_TIMEOUT') ? (int)MW_LLM_TIMEOUT : 60,
        CURLOPT_CONNECTTIMEOUT => 5,
    ]);
    $body = curl_exec($ch);
    $ok = curl_errno($ch) === 0 && (int)curl_getinfo($ch, CURLINFO_HTTP_CODE) === 200;
    curl_close($ch);
    if (!$ok || !is_string($body)) return null;

    $j = json_decode($body, true);
    $text = is_array($j) ? ($j['choices'][0]['message']['content'] ?? null) : null;
    if (!is_string($text)) return null;
    $text = trim(preg_replace('/^```\w*\s*|\s*```$/', '', trim($text)));
    $text = preg_replace('/\s+/', ' ', trim($text, " \t\"'`"));
    if ($text === '' || strlen($text) > 120) return null;
    return $text;
}
