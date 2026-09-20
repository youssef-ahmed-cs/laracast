<?php

namespace App\Http\Controllers\V1\Auth;

use App\Http\Controllers\Controller;
use App\Notifications\SendOtpVerificationMail;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;

class VerifyEmailController extends Controller
{
    public function sendOtp(Request $request)
    {
        $request->validate([
            'email' => 'required|email|exists:users,email',
        ]);

        $email = $request->email;

        $user = User::where('email', $email)->first();

        if ($user->hasVerifiedEmail()) {
            return response()->json([
                'message' => 'Your email is already verified.'
            ], 422);
        }

        // 1. Generate a secure 6-digit random code
        $otp = random_int(100000, 999999);

        // 2. Cache the OTP securely bound to the user's email for 10 minutes
        Cache::put('otp_' . $email, $otp, now()->addMinutes(10));

        // 3. Retrieve user and fire notification
        $user = User::where('email', $email)->first();
        $user->notify(new SendOtpVerificationMail($otp));

        return response()->json([
            'status' => 'success',
            'message' => 'Verification code sent to your email.'
        ], 200);
    }

    /**
     * Step B: Verify the Received OTP
     */
    public function verifyOtp(Request $request)
    {
        $request->validate([
            'email' => 'required|email|exists:users,email',
            'otp' => 'required|numeric',
        ]);

        $email = $request->email;
        $userOtp = $request->otp;

        // 1. Fetch the code stored in the cache
        $cachedOtp = Cache::get('otp_' . $email);

        // 2. Fail if expired or missing entirely
        if (!$cachedOtp) {
            return response()->json([
                'status' => 'error',
                'message' => 'Your OTP has expired. Please request a new one.'
            ], 422);
        }

        // 3. Fail if values do not strictly match
        if ((int)$userOtp !== (int)$cachedOtp) {
            return response()->json([
                'status' => 'error',
                'message' => 'Invalid verification code.'
            ], 422);
        }

        // 4. Mark user verified and flush the single-use token
        $user = User::where('email', $email)->first();

        if (!$user->hasVerifiedEmail()) {
            $user->markEmailAsVerified(); // Sets email_verified_at timestamp
        }

        Cache::forget('otp_' . $email);

        return response()->json([
            'status' => 'success',
            'message' => 'Email verified successfully.'
        ], 200);
    }
}
