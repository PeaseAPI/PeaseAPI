<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * 渠道类型枚举
 * 对标 new-api model/channel_type.go
 */
enum ChannelType: int
{
    // OpenAI 兼容
    case OPENAI = 1;
    case API2D = 2;
    case AZURE = 3;
    case ANTHROPIC = 4;
    case OPENAI_COMPATIBLE = 5;
    case OPENAI_TOKEN = 6;
    case PATH = 7;
    case CUSTOM = 8;
    case BAIDU = 9;
    case ZHIPU = 10;
    case ALI = 11;
    case XUNFEI = 12;
    case AI360 = 13;
    case OPENROUTER = 14;
    case OPENAI_SUM = 15;
    case MOONSHOT = 16;
    case BAIDU_V2 = 17;
    case SILICONFLOW = 18;
    case STREAM = 19;
    case CODEX = 20;
    case VERTEX = 21;
    case AWS = 22;
    case COHERE = 23;
    case CLOUDFLARE = 24;
    case GEM = 25;
    case PALM = 26;
    case JINSHAN = 27;
    case MOKA_AI = 28;
    case ASHMOON = 29;
    case LINGYI_WANWU = 30;
    case COHERE_V2 = 31;
    case OLLAMA = 32;
    case COZE = 33;
    case OPENAI_DASHBOARD = 34;
    case YIMG_CLOUD = 35;
    case DIFY = 36;
    case XINFERENCE = 37;
    case VOLCENGINE = 38;
    case ZHIPU_V4 = 39;
    case PERPLEXITY = 40;
    case SANLIAN = 41;
    case SUB_MODEL = 42;
    case REPLICATE = 43;
    case XAI = 44;
    case MISTRAL = 45;
    case JINA = 46;
    case ADVANCED_CUSTOM = 47;
    case TENCENT = 48;
    case GOOGLE_GEMINI = 50;

    // 别名常量 (使用唯一值)
    case DEEPSEEK = 51;
    case QWEN = 52;
    case HUNYUAN = 53;
    case GROQ = 54;
    case STABILITY = 55;
    case DOUBAO = 56;
    case MINIMAX = 57;
    case YI = 58;
    case STEP = 59;

    // 任务类型
    case SUNO = 60;
    case MIDJOURNEY = 61;
    case KLING = 62;
    case JIMENG = 63;
    case SORA = 64;
    case VIDU = 65;
    case HAILUO = 66;
    case IMAGE = 67;

    // 运营商云
    case CHINA_MOBILE = 70;  // 移动云
    case CHINA_UNICOM = 71;  // 联通云

    public function label(): string
    {
        return match ($this) {
            self::OPENAI, self::OPENAI_SUM, self::OPENAI_COMPATIBLE, self::OPENAI_DASHBOARD, self::OPENAI_TOKEN => 'OpenAI',
            self::API2D => 'API2D',
            self::AZURE => 'Azure OpenAI',
            self::ANTHROPIC => 'Anthropic Claude',
            self::GOOGLE_GEMINI, self::PALM, self::GEM => 'Google Gemini',
            self::VERTEX => 'Google Vertex AI',
            self::AWS => 'AWS Bedrock',
            self::CUSTOM, self::ADVANCED_CUSTOM, self::PATH => '自定义',
            self::BAIDU, self::BAIDU_V2 => '百度文心',
            self::ZHIPU, self::ZHIPU_V4 => '智谱AI',
            self::ALI, self::QWEN => '阿里通义',
            self::XUNFEI => '讯飞星火',
            self::TENCENT, self::HUNYUAN => '腾讯混元',
            self::MOONSHOT => 'Moonshot',
            self::DEEPSEEK => 'DeepSeek',
            self::MISTRAL => 'Mistral',
            self::OLLAMA => 'Ollama',
            self::COHERE, self::COHERE_V2 => 'Cohere',
            self::STABILITY => 'Stability AI',
            self::GROQ => 'Groq',
            self::COZE => 'Coze',
            self::DOUBAO, self::VOLCENGINE => '豆包/火山引擎',
            self::MINIMAX => 'MiniMax',
            self::YI, self::LINGYI_WANWU => '零一万物',
            self::PERPLEXITY => 'Perplexity',
            self::SILICONFLOW => 'SiliconFlow',
            self::STEP => '阶跃星辰',
            self::CODEX => 'Codex',
            self::CLOUDFLARE => 'Cloudflare Workers AI',
            self::REPLICATE => 'Replicate',
            self::XAI => 'xAI Grok',
            self::JINA => 'Jina AI',
            self::XINFERENCE => 'Xinference',
            self::MOKA_AI => 'MokaAI',
            self::AI360 => '360智脑',
            self::SUB_MODEL => '子模型',
            self::DIFY => 'Dify',
            self::OPENROUTER => 'OpenRouter',
            self::JINSHAN => '金山',
            self::ASHMOON => 'Ashmoon',
            self::SANLIAN => '三联',
            self::YIMG_CLOUD => 'YimgCloud',
            self::STREAM => 'Stream',
            self::SUNO => 'Suno',
            self::MIDJOURNEY => 'Midjourney',
            self::KLING => 'Kling',
            self::JIMENG => '即梦',
            self::SORA => 'Sora',
            self::VIDU => 'Vidu',
            self::HAILUO => '海螺',
            self::IMAGE => 'Image',
            self::CHINA_MOBILE => '移动云',
            self::CHINA_UNICOM => '联通云',
            default => '未知',
        };
    }

    public function baseUrl(): string
    {
        return match ($this) {
            self::OPENAI, self::OPENAI_SUM, self::OPENAI_DASHBOARD, self::OPENAI_TOKEN, self::OPENAI_COMPATIBLE => 'https://api.openai.com',
            self::AZURE, self::API2D => '',
            self::ANTHROPIC => 'https://api.anthropic.com',
            self::GOOGLE_GEMINI, self::PALM, self::GEM => 'https://generativelanguage.googleapis.com',
            self::VERTEX => 'https://us-central1-aiplatform.googleapis.com',
            self::AWS => 'https://bedrock-runtime.us-east-1.amazonaws.com',
            self::DEEPSEEK => 'https://api.deepseek.com',
            self::MOONSHOT => 'https://api.moonshot.cn',
            self::ZHIPU, self::ZHIPU_V4 => 'https://open.bigmodel.cn',
            self::ALI, self::QWEN => 'https://dashscope.aliyuncs.com',
            self::BAIDU, self::BAIDU_V2 => 'https://aip.baidubce.com',
            self::TENCENT, self::HUNYUAN => 'https://hunyuan.tencentcloudapi.com',
            self::XUNFEI => 'https://spark-api.xf-yun.com',
            self::MISTRAL => 'https://api.mistral.ai',
            self::GROQ => 'https://api.groq.com',
            self::COHERE, self::COHERE_V2 => 'https://api.cohere.ai',
            self::PERPLEXITY => 'https://api.perplexity.ai',
            self::SILICONFLOW => 'https://api.siliconflow.cn',
            self::OLLAMA => 'http://localhost:11434',
            self::COZE => 'https://api.coze.cn',
            self::DIFY => 'http://localhost:3000',
            self::CLOUDFLARE => 'https://api.cloudflare.com',
            self::MINIMAX => 'https://api.minimax.chat',
            self::REPLICATE => 'https://api.replicate.com',
            self::XAI => 'https://api.x.ai',
            self::XINFERENCE => 'http://localhost:9997',
            self::LINGYI_WANWU, self::YI => 'https://api.lingyiwanwu.com',
            self::MOKA_AI => 'https://api.mokaai.com',
            self::AI360 => 'https://api.360.cn',
            self::JINA => 'https://api.jina.ai',
            self::VOLCENGINE, self::DOUBAO => 'https://ark.cn-beijing.volces.com',
            self::CODEX => 'https://api.codex.io',
            self::STABILITY => 'https://api.stability.ai',
            self::OPENROUTER => 'https://openrouter.ai',
            self::CHINA_MOBILE => 'https://api.ecloud.com',
            self::CHINA_UNICOM => 'https://ai.cucloud.cn',
            default => '',
        };
    }
}
