<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Request Decompression Middleware - 对标 new-api middleware/decompress.go
 * 
 * 支持解压 gzip, deflate 请求体
 */
class DecompressRequest
{
    public function handle(Request $request, Closure $next): Response
    {
        $contentEncoding = $request->header('Content-Encoding', '');
        
        if ($contentEncoding === 'gzip') {
            $content = $request->getContent();
            $decoded = gzdecode($content);
            if ($decoded !== false) {
                $request->setContent($decoded);
                $request->headers->remove('Content-Encoding');
            }
        } elseif ($contentEncoding === 'deflate') {
            $content = $request->getContent();
            $decoded = gzuncompress($content);
            if ($decoded !== false) {
                $request->setContent($decoded);
                $request->headers->remove('Content-Encoding');
            }
        }
        
        return $next($request);
    }
}