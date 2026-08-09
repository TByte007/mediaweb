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

function mwLlmParallel(): int
{
    $n = defined('MW_LLM_PARALLEL') ? (int)MW_LLM_PARALLEL : 1;
    return max(1, min(16, $n));
}

/** Parse chat completion body → title string, or null. */
function mwLlmParseContent(string $body): ?string
{
    $j = json_decode($body, true);
    $text = is_array($j) ? ($j['choices'][0]['message']['content'] ?? null) : null;
    if (!is_string($text)) return null;
    $text = trim(preg_replace('/^```\w*\s*|\s*```$/', '', trim($text)));
    $text = preg_replace('/\s+/', ' ', trim($text, " \t\"'`"));
    if ($text === '' || strlen($text) > 220) return null;
    return $text;
}

/** @return \CurlHandle|resource */
function mwLlmCurlChat(string $system, string $user)
{
    $payload = [
        'messages' => [
            ['role' => 'system', 'content' => $system],
            ['role' => 'user', 'content' => $user],
        ],
        'temperature' => 0.1,
        'max_tokens' => 256,
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
    return $ch;
}

/** One-shot chat completion; null on failure. Thinking disabled. */
function mwLlmChat(string $system, string $user): ?string
{
    if (!mwLlmEnabled()) return null;
    $ch = mwLlmCurlChat($system, $user);
    $body = curl_exec($ch);
    $ok = curl_errno($ch) === 0 && (int)curl_getinfo($ch, CURLINFO_HTTP_CODE) === 200;
    curl_close($ch);
    return ($ok && is_string($body)) ? mwLlmParseContent($body) : null;
}

/**
 * Parallel chat completions (curl_multi). Returns ?string per user, same order.
 * Chunks by MW_LLM_PARALLEL (1–16). Parallel 1 uses mwLlmChat.
 *
 * @param list<string> $users
 * @return list<?string>
 */
function mwLlmChatMany(string $system, array $users): array
{
    $n = count($users);
    if ($n === 0) return [];
    if (!mwLlmEnabled()) return array_fill(0, $n, null);

    $parallel = mwLlmParallel();
    if ($parallel === 1 || $n === 1) {
        $out = [];
        foreach ($users as $user) $out[] = mwLlmChat($system, $user);
        return $out;
    }

    $results = array_fill(0, $n, null);
    foreach (array_chunk($users, $parallel) as $offset => $chunk) {
        $base = $offset * $parallel;
        $mh = curl_multi_init();
        $handles = [];
        foreach ($chunk as $j => $user) {
            $ch = mwLlmCurlChat($system, $user);
            curl_multi_add_handle($mh, $ch);
            $handles[] = [$ch, $base + $j];
        }
        $running = null;
        do {
            $status = curl_multi_exec($mh, $running);
            if ($running) curl_multi_select($mh, 1.0);
        } while ($running > 0 && $status === CURLM_OK);

        foreach ($handles as [$ch, $i]) {
            $body = curl_multi_getcontent($ch);
            $ok = curl_errno($ch) === 0 && (int)curl_getinfo($ch, CURLINFO_HTTP_CODE) === 200;
            $results[$i] = ($ok && is_string($body)) ? mwLlmParseContent($body) : null;
            curl_multi_remove_handle($mh, $ch);
            curl_close($ch);
        }
        curl_multi_close($mh);
    }
    return $results;
}
