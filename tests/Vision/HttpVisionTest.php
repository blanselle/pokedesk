<?php

namespace App\Tests\Vision;

use App\Vision\HttpVision;
use App\Vision\VisionException;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;

final class HttpVisionTest extends TestCase
{
    public function testEmbedSendsOneFilePartPerImageAndReadsTheVectors(): void
    {
        $captured = [];
        $client = new MockHttpClient(function (string $method, string $url, array $options) use (&$captured) {
            // The client turns an iterable body into a callable that returns chunks until an empty string.
            $body = '';
            while ('' !== $chunk = ($options['body'])(16372)) {
                $body .= $chunk;
            }
            $captured = ['method' => $method, 'url' => $url, 'body' => $body, 'headers' => $options['headers']];

            return new MockResponse(json_encode(['model' => 'clip-b32', 'vectors' => [[0.1, 0.2], [0.3, 0.4]]]), ['response_headers' => ['content-type: application/json']]);
        }, 'http://vision/');

        $embeddings = (new HttpVision($client))->embed(['first image', 'second image']);

        self::assertSame('POST', $captured['method']);
        self::assertSame('http://vision/embed', $captured['url']);
        self::assertSame(2, substr_count($captured['body'], 'Content-Disposition: form-data; name="images[')); // one part per image
        self::assertStringContainsString('first image', $captured['body']);
        self::assertStringContainsString('second image', $captured['body']);
        self::assertSame('clip-b32', $embeddings->model);
        self::assertSame([[0.1, 0.2], [0.3, 0.4]], $embeddings->vectors);
    }

    public function testScanDecodesTheCards(): void
    {
        $client = new MockHttpClient(new MockResponse(json_encode([
            'model' => 'clip-b32',
            'cards' => [['quad' => [[0, 0], [1, 0], [1, 1], [0, 1]], 'crop' => base64_encode('jpeg-bytes'), 'embeddings' => [[0.5], [0.6]]]],
        ]), ['response_headers' => ['content-type: application/json']]), 'http://vision/');

        $result = (new HttpVision($client))->scan('photo');

        self::assertCount(1, $result->cards);
        self::assertSame('jpeg-bytes', $result->cards[0]->crop);
        self::assertSame([[0.5], [0.6]], $result->cards[0]->embeddings);
    }

    public function testFailuresBecomeVisionExceptions(): void
    {
        $client = new MockHttpClient(new MockResponse('nope', ['http_code' => 422]), 'http://vision/');

        $this->expectException(VisionException::class);
        (new HttpVision($client))->scan('photo');
    }

    public function testAnAnswerWithTheWrongNumberOfVectorsIsRejected(): void
    {
        $client = new MockHttpClient(new MockResponse(json_encode(['model' => 'clip-b32', 'vectors' => [[0.1]]]), ['response_headers' => ['content-type: application/json']]), 'http://vision/');

        $this->expectException(VisionException::class);
        (new HttpVision($client))->embed(['a', 'b']);
    }
}
