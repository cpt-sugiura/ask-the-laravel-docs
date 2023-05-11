<?php

namespace CosmeDev\AskDocs;

use JetBrains\PhpStorm\NoReturn;
use OpenAI;
use OpenAI\Client;

class AskDocsReceiveQuestionFromPost
{
    public Client $openai;
    public RedisHelper $redis;

    public function __construct()
    {
        $this->openai = OpenAI::client(config('openai.api_key'));
        $this->redis = new RedisHelper();
    }

    #[NoReturn] public function start(string $question): void
    {
        if (!$this->redis->hasIndex(config('redis.index_name'))) {
            echo "Index doesn't exist so we'll create it (this only happens one time)...\n";
            $docLoader = new DocumentationLoader($this->redis, $this->openai);
            $docLoader->loadDocs();
        }

        if(is_file(__DIR__ . '/chatHistory.txt')) {
            $chatHistory = unserialize(file_get_contents(__DIR__ . '/chatHistory.txt'));
        }else{
            $chatHistory = [];
        }
        $question = $this->questionWithHistory($question, $chatHistory);
        $chatHistory[] = 'User: ' . $question;
        $relevantDocs = $this->relevantDocs($question);
        $questionWithContext = $this->questionWithContext($question, array_column($relevantDocs, 'text'));
        $response = $this->chat($questionWithContext);
        $chatHistory[] = 'Assistant: ' . $question;
        $this->showResponse($response, array_column($relevantDocs, 'url'));
        file_put_contents(__DIR__ . '/chatHistory.txt', serialize($chatHistory));
    }

    // We ask OpenAI to create a question that contains the whole context of the
    // conversation so we can send a single question with the context and give
    // the illusion that it remembers the conversation history
    public function questionWithHistory($question, $chatHistory)
    {
        if (! $chatHistory) {
            return $question;
        }

        $template = <<<'TEXT'
Given the following conversation and a follow up question, rephrase the follow up question to be a standalone question.

Chat History:
{chat_history}
Follow Up Input: {question}
Standalone question:
TEXT;

        $template = str_replace('{question}', $question, $template);
        $template = str_replace('{chat_history}', implode("\n", $chatHistory), $template);

        return $this->chat($template);
    }

    public function chat($message): string
    {
        $response = $this->openai->chat()->create([
            'model' => config('openai.completions_model'),
            'messages' => [
                ['role' => 'user', 'content' => $message],
            ],
        ]);

        return $response->choices[0]->message->content;
    }

    // We convert the question into the embedding representation using OpenAI
    // embeddings endpoint.
    // We then use those embeddings to get the 4 most semantically similar documentation sections,
    //
    // We will send those sections as context to OpenAI so it can answer the question better.
    public function relevantDocs($question): array
    {
        $result = $this->openai->embeddings()->create([
            'input' => $question,
            'model' => config('openai.embeddings_model')
        ]);

        // Encode the embeddings into a byte string. See helpers.php
        $packed = encode($result->embeddings[0]->embedding);
        return $this->redis->vectorSearch(config('redis.index_name'), 4, $packed, vectorName: "content_vector", returnFields: ['text', 'url']);
    }

    // We build the propmt with the context (the previously retrieved documentation sections)
    public function questionWithContext($question, $context): array|string
    {
        $template = <<<'TEXT'
Answer the question based on the context below. With code samples if possible.

Context:
{context}

question: {question}
TEXT;

        $template = str_replace('{question}', $question, $template);
        return str_replace('{context}', implode("\n", $context), $template);
    }

    public function showResponse($response, $sources): void
    {
        echo json_encode(['response' => $response, 'sources' => $sources]);
    }
}
