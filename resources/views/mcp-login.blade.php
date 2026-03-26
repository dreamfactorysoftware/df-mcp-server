<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Sign in to DreamFactory</title>
    <style>
        * { margin: 0; padding: 0; box-sizing: border-box; }
        body {
            font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, Oxygen, sans-serif;
            background: #f5f5f5;
            min-height: 100vh;
            display: flex;
            align-items: center;
            justify-content: center;
        }
        .login-card {
            background: #fff;
            border-radius: 8px;
            box-shadow: 0 2px 12px rgba(0,0,0,0.1);
            padding: 40px;
            width: 100%;
            max-width: 400px;
        }
        .logo {
            text-align: center;
            margin-bottom: 24px;
        }
        .logo svg { height: 40px; }
        .logo-text {
            font-size: 24px;
            font-weight: 600;
            color: #e8601c;
        }
        h2 {
            font-size: 18px;
            font-weight: 600;
            color: #333;
            margin-bottom: 20px;
        }
        .subtitle {
            font-size: 13px;
            color: #888;
            margin-bottom: 24px;
        }
        .error {
            background: #fef2f2;
            border: 1px solid #fecaca;
            color: #dc2626;
            padding: 10px 14px;
            border-radius: 6px;
            font-size: 14px;
            margin-bottom: 16px;
        }
        label {
            display: block;
            font-size: 14px;
            font-weight: 500;
            color: #555;
            margin-bottom: 6px;
        }
        input[type="email"],
        input[type="password"] {
            width: 100%;
            padding: 10px 12px;
            border: 1px solid #d1d5db;
            border-radius: 6px;
            font-size: 14px;
            margin-bottom: 16px;
            outline: none;
            transition: border-color 0.15s;
        }
        input:focus {
            border-color: #e8601c;
            box-shadow: 0 0 0 2px rgba(232, 96, 28, 0.15);
        }
        button[type="submit"] {
            width: 100%;
            padding: 10px;
            background: #1e1e2e;
            color: #fff;
            border: none;
            border-radius: 6px;
            font-size: 14px;
            font-weight: 500;
            cursor: pointer;
            transition: background 0.15s;
        }
        button[type="submit"]:hover { background: #2d2d42; }
        .divider {
            display: flex;
            align-items: center;
            margin: 20px 0;
            color: #aaa;
            font-size: 13px;
        }
        .divider::before, .divider::after {
            content: '';
            flex: 1;
            border-top: 1px solid #e5e7eb;
        }
        .divider span { padding: 0 12px; }
        .oauth-buttons { display: flex; flex-direction: column; gap: 8px; }
        .oauth-btn {
            display: block;
            width: 100%;
            padding: 10px;
            background: #fff;
            color: #333;
            border: 1px solid #d1d5db;
            border-radius: 6px;
            font-size: 14px;
            font-weight: 500;
            cursor: pointer;
            text-align: center;
            text-decoration: none;
            transition: background 0.15s;
        }
        .oauth-btn:hover { background: #f9fafb; }
    </style>
</head>
<body>
    <div class="login-card">
        <div class="logo">
            <span class="logo-text">DreamFactory</span>
        </div>

        <h2>Sign in</h2>
        <p class="subtitle">Authenticate to connect with {{ $serviceName ?? 'MCP' }}</p>

        @if (!empty($error))
            <div class="error">{{ $error }}</div>
        @endif

        <form method="POST" action="{{ $loginUrl }}">
            <label for="email">Email</label>
            <input type="email" id="email" name="email" value="{{ old('email', $email ?? '') }}" required autofocus>

            <label for="password">Password</label>
            <input type="password" id="password" name="password" required>

            <input type="hidden" name="state" value="{{ $state }}">
            <input type="hidden" name="client_id" value="{{ $clientId }}">
            <input type="hidden" name="redirect_uri" value="{{ $redirectUri }}">
            <input type="hidden" name="code_challenge" value="{{ $codeChallenge }}">
            <input type="hidden" name="code_challenge_method" value="{{ $codeChallengeMethod }}">
            <input type="hidden" name="scope" value="{{ $scope }}">

            <button type="submit">Sign in</button>
        </form>

        @if (!empty($oauthServices))
            <div class="divider"><span>or</span></div>
            <div class="oauth-buttons">
                @foreach ($oauthServices as $oauth)
                    <a class="oauth-btn" href="{{ $oauthRedirectUrl }}?provider={{ urlencode($oauth['path'] ?? $oauth['name']) }}&state={{ urlencode($state) }}">
                        Sign in with {{ $oauth['label'] ?? $oauth['name'] ?? 'OAuth' }}
                    </a>
                @endforeach
            </div>
        @endif
    </div>
</body>
</html>
