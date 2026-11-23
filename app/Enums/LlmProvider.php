<?php

namespace App\Enums;

enum LlmProvider: string
{
    case OpenAi = 'openai';
    case Anthropic = 'anthropic';
    case Gemini = 'gemini';
    case Groq = 'groq';
    case DeepSeek = 'deepseek';

    public function label(): string
    {
        return match ($this) {
            self::OpenAi => 'OpenAI',
            self::Anthropic => 'Anthropic',
            self::Gemini => 'Google Gemini',
            self::Groq => 'Groq',
            self::DeepSeek => 'DeepSeek',
        };
    }
}
