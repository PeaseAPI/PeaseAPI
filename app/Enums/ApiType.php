<?php

namespace App\Enums;

/**
 * API Provider Types (映射到渠道类型)
 * 0-58 对应不同的 AI 服务提供商
 */
enum ApiType: int
{
    case OPENAI = 0;
    case ANTHROPIC = 1;
    case MIDJOURNEY = 2;
    case AZURE = 3;
    case OLLAMA = 4;
    case PALM = 5;
    case BAIDU = 6;
    case ZHIPU = 7;
    case ALI = 8;
    case XUNFEI = 9;
    case AWS = 10;
    case VERTEX_AI = 11;
    case ANTHROPIC_CLAUDE = 14;
    case BAIDU_WENXIN = 15;
    case ZHIPU_BIGMODEL = 16;
    case ALI_DASHSCOPE = 17;
    case XUNFEI_SPARK = 18;
    case QIHOO_360 = 19;
    case OPENROUTER = 20;
    case TENCENT_HUNYUAN = 23;
    case GOOGLE_GEMINI = 24;
    case MOONSHOT_KIMI = 25;
    case PERPLEXITY = 27;
    case LINGYIWANWU = 31;
    case AWS_BEDROCK = 33;
    case COHERE = 34;
    case MINIMAX = 35;
    case SUNO = 36;
    case DIFY = 37;
    case JINA = 38;
    case CLOUDFLARE_WORKERS = 39;
    case SILICONFLOW = 40;
    case VERTEX_AI_GEMINI = 41;
    case MISTRAL = 42;
    case DEEPSEEK = 43;
    case MOKA_ML = 44;
    case VOLCENGINE = 45;
    case BAIDU_V2 = 46;
    case XINFERENCE = 47;
    case XAI_GROK = 48;
    case COZE = 49;
    case KLING = 50;
    case JIMENG = 51;
    case VIDU = 52;
    case SUBMODEL = 53;
    case DOUBAO_VIDEO = 54;
    case SORA = 55;
    case REPLICATE = 56;
    case CODEX = 57;
    case CUSTOM = 58;
    case CHINA_MOBILE = 59;  // 移动云
    case CHINA_UNICOM = 60;  // 联通云
    case GROQ = 61;          // Groq
    case STABILITY = 62;     // Stability AI
    case STEP = 63;          // StepFun (阶跃星辰)
    case JINSHAN = 64;       // 金山
    case ASHMOON = 65;       // Ashmoon
    case SANLIAN = 66;       // Sanlian
    case YIMG_CLOUD = 67;    // YimgCloud

    public function label(): string
    {
        return match ($this) {
            self::OPENAI => 'OpenAI',
            self::ANTHROPIC => 'Anthropic',
            self::MIDJOURNEY => 'Midjourney',
            self::AZURE => 'Azure OpenAI',
            self::OLLAMA => 'Ollama',
            self::PALM => 'PaLM',
            self::BAIDU => 'Baidu',
            self::ZHIPU => 'Zhipu',
            self::ALI => 'Alibaba',
            self::XUNFEI => 'iFlytek',
            self::AWS => 'AWS',
            self::VERTEX_AI => 'Vertex AI',
            self::ANTHROPIC_CLAUDE => 'Anthropic Claude',
            self::BAIDU_WENXIN => 'Baidu Wenxin',
            self::ZHIPU_BIGMODEL => 'Zhipu BigModel',
            self::ALI_DASHSCOPE => 'Alibaba Dashscope',
            self::XUNFEI_SPARK => 'iFlytek Spark',
            self::QIHOO_360 => '360',
            self::OPENROUTER => 'OpenRouter',
            self::TENCENT_HUNYUAN => 'Tencent Hunyuan',
            self::GOOGLE_GEMINI => 'Google Gemini',
            self::MOONSHOT_KIMI => 'Moonshot Kimi',
            self::PERPLEXITY => 'Perplexity',
            self::LINGYIWANWU => 'LingYiWanWu',
            self::AWS_BEDROCK => 'AWS Bedrock',
            self::COHERE => 'Cohere',
            self::MINIMAX => 'MiniMax',
            self::SUNO => 'Suno',
            self::DIFY => 'Dify',
            self::JINA => 'Jina',
            self::CLOUDFLARE_WORKERS => 'Cloudflare Workers',
            self::SILICONFLOW => 'SiliconFlow',
            self::VERTEX_AI_GEMINI => 'Vertex AI Gemini',
            self::MISTRAL => 'Mistral',
            self::DEEPSEEK => 'DeepSeek',
            self::MOKA_ML => 'Moka',
            self::VOLCENGINE => 'VolcEngine',
            self::BAIDU_V2 => 'Baidu V2',
            self::XINFERENCE => 'Xinference',
            self::XAI_GROK => 'xAI Grok',
            self::COZE => 'Coze',
            self::KLING => 'Kling',
            self::JIMENG => 'JiMeng',
            self::VIDU => 'Vidu',
            self::SUBMODEL => 'Submodel',
            self::DOUBAO_VIDEO => 'Doubao Video',
            self::SORA => 'Sora',
            self::REPLICATE => 'Replicate',
            self::CODEX => 'Codex',
            self::CUSTOM => 'Custom',
            self::CHINA_MOBILE => 'China Mobile Cloud',
            self::CHINA_UNICOM => 'China Unicom Cloud',
            self::GROQ => 'Groq',
            self::STABILITY => 'Stability AI',
            self::STEP => 'StepFun',
            self::JINSHAN => 'Jinshan',
            self::ASHMOON => 'Ashmoon',
            self::SANLIAN => 'Sanlian',
            self::YIMG_CLOUD => 'YimgCloud',
        };
    }
}
