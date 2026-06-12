<?php

namespace App\Http\Controllers;

use App\Services\CardIssueService;
use Illuminate\Http\Response;
use RuntimeException;

class ZucchiniCardController extends Controller
{
    public function __invoke(CardIssueService $cards): Response
    {
        try {
            $card = $cards->issueAnonymous();
        } catch (RuntimeException $exception) {
            return response($exception->getMessage(), 503, ['Content-Type' => 'text/plain; charset=utf-8']);
        }

        return response($card->access_code."\n", 201, ['Content-Type' => 'text/plain; charset=utf-8']);
    }
}
