<?php

namespace App\Http\Controllers\Web;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;

class ChromeController extends Controller
{
    public function privacyPolicy()
    {
        return view('chrome.privacy_policy');
    }
}
