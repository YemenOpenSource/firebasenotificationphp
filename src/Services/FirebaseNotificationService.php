<?php

namespace SendFireBaseNotificationPHP\Services;

use Firebase\JWT\JWT;
use Illuminate\Support\Facades\Http;
use Illuminate\Database\Eloquent\Model;
use SendFireBaseNotificationPHP\Repositories\FirebaseNotificationRepository;

class FirebaseNotificationService
{
    protected $fcmUrl;
    protected $projectId;
    protected $credentialsFile;
    protected $repository;

    public function __construct(FirebaseNotificationRepository $repository)
    {
        $this->projectId = config('firebase.project_id');
        $this->fcmUrl = "https://fcm.googleapis.com/" . config('firebase.version') . "/projects/{$this->projectId}/messages:send";
        $this->credentialsFile = config('firebase.credentials_file');
        $this->repository = $repository;
    }

    protected function getAccessToken(): string
    {
        if (!is_readable($this->credentialsFile)) {
            throw new \Exception('Firebase credentials file is missing or unreadable.');
        }

        $credentials = json_decode(file_get_contents($this->credentialsFile), true);

        if (!is_array($credentials) || empty($credentials['client_email']) || empty($credentials['private_key'])) {
            throw new \Exception('Invalid Firebase credentials file.');
        }

        $now = time();
        $jwt = JWT::encode([
            'iss' => $credentials['client_email'],
            'scope' => 'https://www.googleapis.com/auth/firebase.messaging',
            'aud' => 'https://oauth2.googleapis.com/token',
            'iat' => $now,
            'exp' => $now + 3600,
        ], $credentials['private_key'], 'RS256');

        $response = Http::asForm()->post('https://oauth2.googleapis.com/token', [
            'grant_type' => 'urn:ietf:params:oauth:grant-type:jwt-bearer',
            'assertion' => $jwt,
        ]);

        if ($response->failed() || empty($response->json('access_token'))) {
            throw new \Exception('Failed to retrieve Firebase access token.');
        }

        return $response->json('access_token');
    }

    protected function sendFirebaseRequest(array $headers, array $payload)
    {
        $response = Http::withHeaders($headers)
            ->post($this->fcmUrl, $payload);

        if ($response->failed()) {
            throw new \Exception("Firebase request failed: " . $response->body());
        }

        return $response->json();
    }

    public function sendNotification(string $deviceToken, string $title, string $body)
    {
        $accessToken = $this->getAccessToken();

        $headers = [
            "Authorization" => "Bearer $accessToken",
            "Content-Type" => "application/json",
        ];

        $payload = [
            "message" => [
                "token" => $deviceToken,
                "notification" => [
                    "title" => $title,
                    "body" => $body,
                ],
            ],
        ];

        return $this->sendFirebaseRequest($headers, $payload);
    }

    public function sendNotificationToAll(Model $model, string $title, string $body, string $tokenColumn = 'fcm_token')
    {
        $users = $this->repository->getAllUsersWithTokens($model, $tokenColumn);

        if ($users->isEmpty()) {
            return ['success' => false, 'message' => 'No users with valid FCM tokens found.'];
        }

        foreach ($users as $user) {
            if (!empty($user->$tokenColumn)) {
                $this->sendNotification($user->$tokenColumn, $title, $body);
            }
        }

        return ['success' => true, 'message' => 'Notifications sent to all users.'];
    }

    public function sendNotificationToSingle(Model $model, int $id, string $title, string $body, string $tokenColumn = 'fcm_token')
    {
        $user = $this->repository->getUserWithTokenById($model, $id, $tokenColumn);

        if (!$user || empty($user->$tokenColumn)) {
            return ['success' => false, 'message' => 'User with valid FCM token not found.'];
        }

        $response = $this->sendNotification($user->$tokenColumn, $title, $body);

        return ['success' => true, 'message' => 'Notification sent to the user.', 'response' => $response];
    }

    public function sendNotificationToTopic(string $topic, string $title, string $body)
    {
        $accessToken = $this->getAccessToken();

        $headers = [
            "Authorization" => "Bearer $accessToken",
            "Content-Type" => "application/json",
        ];

        $payload = [
            "message" => [
                "topic" => $topic,
                "notification" => [
                    "title" => $title,
                    "body" => $body,
                ],
                "apns" => [
                    "payload" => [
                        "aps" => [
                            "sound" => "default",
                        ],
                    ],
                ],
            ],
        ];

        return $this->sendFirebaseRequest($headers, $payload);
    }

    public function sendNotificationWithImage(
        string $deviceToken,
        string $title,
        string $body,
        string $imageUrl
    ) {
        $accessToken = $this->getAccessToken();

        $headers = [
            "Authorization" => "Bearer $accessToken",
            "Content-Type" => "application/json",
        ];

        $payload = [
            "message" => [
                "token" => $deviceToken,

                "notification" => [
                    "title" => $title,
                    "body"  => $body,
                ],

                "data" => [
                    "image" => $imageUrl,
                ],

                "android" => [
                    "notification" => [
                        "image" => $imageUrl,
                        "sound" => "default",
                    ],
                ],

                "apns" => [
                    "payload" => [
                        "aps" => [
                            "mutable-content" => 1,
                            "sound" => "default",
                        ],
                    ],
                    "fcm_options" => [
                        "image" => $imageUrl,
                    ],
                ],
            ],
        ];

        return $this->sendFirebaseRequest($headers, $payload);
    }

    public function sendNotificationWithImageToAll(
        Model $model,
        string $title,
        string $body,
        string $imageUrl,
        string $tokenColumn = 'fcm_token'
    ) {
        $users = $this->repository->getAllUsersWithTokens($model, $tokenColumn);

        if ($users->isEmpty()) {
            return [
                'success' => false,
                'message' => 'No users with valid FCM tokens found.'
            ];
        }

        foreach ($users as $user) {
            if (!empty($user->$tokenColumn)) {

                $this->sendNotificationWithImage(
                    $user->$tokenColumn,
                    $title,
                    $body,
                    $imageUrl
                );
            }
        }

        return [
            'success' => true,
            'message' => 'Notifications with images sent to all users.'
        ];
    }

    public function sendNotificationWithImageToTopic(
        string $topic,
        string $title,
        string $body,
        string $imageUrl
    ) {
        $accessToken = $this->getAccessToken();

        $headers = [
            "Authorization" => "Bearer $accessToken",
            "Content-Type" => "application/json",
        ];

        $payload = [
            "message" => [
                "topic" => $topic,

                "notification" => [
                    "title" => $title,
                    "body"  => $body,
                ],

                "data" => [
                    "image" => $imageUrl,
                ],

                "android" => [
                    "notification" => [
                        "image" => $imageUrl,
                        "sound" => "default",
                    ],
                ],

                "apns" => [
                    "payload" => [
                        "aps" => [
                            "mutable-content" => 1,
                            "sound" => "default",
                        ],
                    ],
                    "fcm_options" => [
                        "image" => $imageUrl,
                    ],
                ],
            ],
        ];

        return $this->sendFirebaseRequest($headers, $payload);
    }

    /**
     * Send notification with custom data and optional image (supports both image and none-image).
     *
     * @param string $deviceToken
     * @param string $title
     * @param string $body
     * @param array $data
     * @param string|null $imageUrl
     * @return array
     */
    public function sendFirebaseWithData(
        string $deviceToken,
        string $title,
        string $body,
        array $data = [],
        ?string $imageUrl = null
    ) {
        $accessToken = $this->getAccessToken();

        $headers = [
            "Authorization" => "Bearer $accessToken",
            "Content-Type" => "application/json",
        ];

        $formattedData = [];
        foreach ($data as $key => $value) {
            $formattedData[(string)$key] = (string)$value;
        }

        if (!empty($imageUrl)) {
            $formattedData['image'] = $imageUrl;
        }

        $message = [
            "token" => $deviceToken,
            "notification" => [
                "title" => $title,
                "body"  => $body,
            ],
        ];

        if (!empty($formattedData)) {
            $message["data"] = $formattedData;
        }

        if (!empty($imageUrl)) {
            $message["android"] = [
                "notification" => [
                    "image" => $imageUrl,
                    "sound" => "default",
                ],
            ];

            $message["apns"] = [
                "payload" => [
                    "aps" => [
                        "mutable-content" => 1,
                        "sound" => "default",
                    ],
                ],
                "fcm_options" => [
                    "image" => $imageUrl,
                ],
            ];
        } else {
            $message["android"] = [
                "notification" => [
                    "sound" => "default",
                ],
            ];

            $message["apns"] = [
                "payload" => [
                    "aps" => [
                        "sound" => "default",
                    ],
                ],
            ];
        }

        $payload = [
            "message" => $message,
        ];

        return $this->sendFirebaseRequest($headers, $payload);
    }
}

