<?php

namespace App\Http\Controllers;

use App\Mail\ForgotPasswordMail;
use App\Models\Patient;
use App\Models\Role;
use Illuminate\Http\Request;
use App\Models\User;
use Carbon\Carbon;
use Exception;
use Illuminate\Support\Str;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Mail;
use Illuminate\Validation\ValidationException;

class AuthController extends Controller
{
    // register user
    /**
     * @unauthenticated
     */
    public function register(Request $request)
    {
        // start transaction
        DB::beginTransaction();

        try {
            $request->validate([
                'name' => 'required|string|max:255',
                'email' => 'required|string|email|max:255',
                'password' => 'required|string|min:8|confirmed',
                'nic' => 'required|string',
                'phone' => 'required|string|max:10',
                'date_of_birth' => 'required|date',
                'gender' => 'required|string|in:male,female',
                'address' => 'required|string',
            ]);

            // check email already exists
            if (User::where('email', $request->email)->exists()) {
                return response()->json(['message' => 'Email already exists'], 400);
            }

            // check if patient already exists
            $existingPatient = Patient::where('nic', $request->nic)->first();

            // check if patient already has a user
            if ($existingPatient && $existingPatient->user_id) {
                return response()->json(['message' => 'Patient with this NIC already has a registered user'], 400);
            }

            // get role
            $role = Role::where('name', "patient")->first();

            $user = User::create([
                'name' => $request->name,
                'email' => $request->email,
                'password' => bcrypt($request->password),
                'role_id' => $role->id
            ]);

            if ($existingPatient) {
                // update existing patient
                $existingPatient->phone = $request->phone;
                $existingPatient->date_of_birth = date('Y-m-d', strtotime($request->date_of_birth));
                $existingPatient->gender = $request->gender;
                $existingPatient->address = $request->address;
                $existingPatient->user_id = $user->id;
                $existingPatient->name = $request->name;
                $existingPatient->save();
            } else {
                Patient::create([
                    'nic' => $request->nic,
                    'phone' => $request->phone,
                    'date_of_birth' => date('Y-m-d', strtotime($request->date_of_birth)),
                    'gender' => $request->gender,
                    'address' => $request->address,
                    'user_id' => $user->id,
                    'name' => $request->name,
                ]);
            }


            // commit transaction
            DB::commit();

            return response()->json(['message' => 'User registered successfully'], 201);
        } catch (ValidationException $e) {
            DB::rollBack();
            return response()->json([
                'message' => $e->validator->errors()->first()
            ], 400);
        } catch (Exception $e) {
            // rollback transaction
            DB::rollBack();

            return response()->json([
                'message' => 'Error creating user',
                'ex' => $e->getMessage(),
            ], 500);
        }
    }

    // login user
    /**
     * @unauthenticated
     */
    public function login(Request $request)
    {
        // start transactions
        DB::beginTransaction();
        try {
            $request->validate([
                'email' => 'required|string|email',
                'password' => 'required|string',
                'rememberMe' => 'boolean',
            ]);

            $user = User::where('email', $request->email)->first();

            if (!$user || !Hash::check($request->password, $user->password)) {
                return response()->json([
                    'message' => 'Email or password is incorrect'
                ], 401);
            }

            // check user status banned
            if ($user->status === 'banned') {
                return response()->json([
                    'message' => 'Your account is banned'
                ], 403);
            }

            // check user is retired
            if ($user->status === 'retired') {
                return response()->json([
                    'message' => 'Your account is retired'
                ], 403);
            }

            // if role is not patient or super_admin , check hospital_id
            if (!in_array($user->role()->pluck('name')->first(), ['patient', 'super_admin'])) {
                if (!$user->hospital_id) {
                    return response()->json([
                        'message' => 'You are not assigned to any hospital. Please contact your administrator.'
                    ], 403);
                }
            }

            // create token
            $token = $this->refresh(
                $user,
                rememberMe: $request->boolean('rememberMe')
            );

            // commit transaction
            DB::commit();


            return response()->json([
                'message' => 'Successfully logged in',
                'user' =>  array(
                    'name' => $user->name,
                    'email' => $user->email,
                    'role' => $user->role()->pluck('name'),
                    'permissions' => $user->permissions()->pluck('name')->unique()->values()
                ),
                'token' => $token,

            ]);
        } catch (ValidationException $e) {
            DB::rollBack();
            return response()->json([
                'message' => $e->validator->errors()->first()
            ], 400);
        } catch (Exception $e) {
            // rollback transaction
            DB::rollBack();

            return response()->json([
                'message' => 'Error logging in',
                'ex' => $e->getMessage(),
            ], 500);
        }
    }

    // get user
    public function user(Request $request)
    {
        $user = $request->user();
        $user_data = array(
            'id' => $user->id,
            'name' => $user->name,
            'email' => $user->email,
            'role' => $user->role()->pluck('name')->first(),
            'permissions' => $user->permissions()->pluck('name')->unique()->values()
        );
        if ($user->role()->pluck('name')->first() == "patient") {
            // get patient from user id
            $patient = Patient::where("user_id", $user->id)->first();
            // add patient_id
            $user_data['patient_id'] = $patient->id;
        } else {
            // add hospital_id
            $user_data['hospital_id'] = $user->hospital_id;
        }
        return response()->json([
            'user' => $user_data
        ]);
    }

    // logout user
    public function logout(Request $request)
    {
        DB::beginTransaction();
        try {
            // Revoke the current token
            $request->user()->currentAccessToken()->delete();

            // remove auth cookie
            setcookie('auth_token', '', time() - 3600, '/', '', false, false);

            // commit transaction
            DB::commit();

            return response()->json([
                'message' => 'Successfully logged out'
            ]);
        } catch (Exception $e) {
            DB::rollBack();
            return response()->json([
                'message' => 'Error logging out'
            ], 500);
        }
    }

    // refresh token
    public static function refresh(User $user, bool $rememberMe = false)
    {
        // abilities
        $abilities = ['*'];
        // create token
        $token = $user->createToken('auth_token', $abilities)->plainTextToken;

        // if remember me is true, set expiration time to 30 days
        if ($rememberMe) {
            $expirationTime = env('REMEMBER_ME_EXPIRATION_TIME', 2592000); // 30 days in seconds
        } else {
            $expirationTime = env('ACCESS_TOKEN_EXPIRATION_TIME', 10800); // 3 hours in seconds
        }

        // save token in cookies
        setcookie('auth_token', $token, time() + $expirationTime, '/', '', false, false);

        return $token;
    }

    // update user email
    public function updateEmail(Request $request)
    {
        // start transaction
        DB::beginTransaction();

        try {
            $request->validate([
                'email' => 'required|string|email|unique:users,email,' . $request->user()->id
            ]);

            $request->user()->email = $request->email;
            $request->user()->save();

            // commit transaction
            DB::commit();

            return response()->json([
                'message' => 'Successfully updated email'
            ]);
        } catch (ValidationException $e) {
            DB::rollBack();
            return response()->json([
                'message' => $e->validator->errors()->first()
            ], 400);
        } catch (Exception $e) {
            // rollback transaction
            DB::rollBack();

            return response()->json([
                'message' => 'Error updating email'
            ], 500);
        }
    }

    // update user password
    public function updatePassword(Request $request)
    {
        // start transaction
        DB::beginTransaction();
        try {
            $request->validate([
                'password' => 'required|string|confirmed',
                'old_password' => 'required|string'
            ]);

            if (!Hash::check($request->old_password, $request->user()->password)) {
                return response()->json([
                    'message' => 'Unauthorized'
                ], 401);
            }

            $request->user()->password = bcrypt($request->password);
            $request->user()->save();

            // commit transaction
            DB::commit();

            // logout all devices
            $request->user()->tokens()->delete();

            // remove auth cookie
            setcookie('auth_token', '', time() - 3600, '/', '', false, false);

            return response()->json([
                'message' => 'Successfully updated password'
            ]);
        } catch (ValidationException $e) {
            DB::rollBack();
            return response()->json([
                'message' => $e->validator->errors()->first()
            ], 400);
        } catch (Exception $e) {
            // rollback transaction
            DB::rollBack();

            return response()->json([
                'message' => 'Error updating password'
            ], 500);
        }
    }

    // update user name
    public function updateName(Request $request)
    {
        // start transaction
        DB::beginTransaction();
        try {
            $request->validate([
                'name' => 'required|string|min:3|max:255'
            ]);

            $request->user()->name = $request->name;
            $request->user()->save();

            // commit transaction
            DB::commit();

            return response()->json([
                'message' => 'Successfully updated name'
            ]);
        } catch (ValidationException $e) {
            DB::rollBack();
            return response()->json([
                'message' => $e->validator->errors()->first()
            ], 400);
        } catch (Exception $e) {
            // rollback transaction
            DB::rollBack();

            return response()->json([
                'message' => 'Error updating name'
            ], 500);
        }
    }

    // forgot password
    /**
     * @unauthenticated
     */
    public function forgotPassword(Request $request)
    {
        // start transaction
        DB::beginTransaction();

        try {
            $request->validate([
                'email' => 'required|string|email|exists:users,email'
            ]);

            $user = User::where('email', $request->email)->first();

            if (!$user) {
                return response()->json([
                    'message' => 'User not found'
                ], 404);
            }

            // Generate reset token
            $token = Str::random(64);

            // Set token expiration (60 minutes from now)
            $expiresAt = Carbon::now()->addMinutes(60);

            // Update user with reset token
            $user->update([
                'reset_password_token' => $token,
                'reset_password_token_expires_at' => $expiresAt
            ]);

            // Generate reset URL
            $resetUrl = url(env('FRONTEND_URL') . '/reset-password?token=' . $token . '&email=' . urlencode($user->email));

            // Send email
            Mail::to($user->email)->send(new ForgotPasswordMail($resetUrl));

            // commit transaction
            DB::commit();

            return response()->json([
                'message' => 'Password reset link sent to your email'
            ]);
        } catch (ValidationException $e) {
            DB::rollBack();
            return response()->json([
                'message' => $e->validator->errors()->first()
            ], 400);
        } catch (Exception $e) {
            // rollback transaction
            DB::rollBack();

            return response()->json([
                'message' => 'Error sending password reset email',
                'ex' => $e->getMessage()
            ], 500);
        }
    }

    // validate token
    public function validateResetToken(Request $request)
    {
        $request->validate([
            'email' => 'required|string|email|exists:users,email',
            'token' => 'required|string'
        ]);

        $user = User::where('email', $request->email)->first();

        if (!$user) {
            return response()->json([
                'message' => 'User not found'
            ], 404);
        }

        if (!$user->isResetTokenValid($request->token)) {
            return response()->json([
                'message' => 'Invalid or expired reset token'
            ], 400);
        }

        return response()->json([
            'message' => 'Valid reset token',
            'valid' => true,
            'email' => $user->email
        ]);
    }

    // reset password
    /**
     * @unauthenticated
     */
    public function resetPassword(Request $request)
    {
        // start transaction
        DB::beginTransaction();

        try {
            $request->validate([
                'email' => 'required|string|email|exists:users,email',
                'token' => 'required|string',
                'password' => 'required|string|min:8|confirmed'
            ]);

            $user = User::where('email', $request->email)->first();

            if (!$user) {
                return response()->json([
                    'message' => 'User not found'
                ], 404);
            }

            // Check if token is valid
            if (!$user->isResetTokenValid($request->token)) {
                return response()->json([
                    'message' => 'Invalid or expired reset token'
                ], 400);
            }

            // Update password
            $user->update([
                'password' => bcrypt($request->password)
            ]);

            // Clear reset token
            $user->clearResetToken();

            // Revoke all existing tokens for security
            $user->tokens()->delete();

            // commit transaction
            DB::commit();

            return response()->json([
                'message' => 'Password reset successfully'
            ]);
        } catch (ValidationException $e) {
            DB::rollBack();
            return response()->json([
                'message' => $e->validator->errors()->first()
            ], 400);
        } catch (Exception $e) {
            // rollback transaction
            DB::rollBack();

            return response()->json([
                'message' => 'Error resetting password',
                'ex' => $e->getMessage()
            ], 500);
        }
    }
}
