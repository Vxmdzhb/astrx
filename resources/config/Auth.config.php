<?php
declare(strict_types=1);

return [
    'Gate' => [
        'grants' => [
            'ADMIN' => [
                '*',
            ],
            'GUEST' => [
                'news.view',
                'comment.post',
                'comment.flag',
                'user.view.public',
                // Chat
                'chat.view',
                'chat.post',
                'chat.pm',
                // Imageboard
                'board.view',
                'board.post',
            ],
            'USER' => [
                'news.view',
                'comment.post',
                'comment.hide.own',
                'comment.delete.own',
                'comment.flag',
                'user.view.public',
                'user.edit.own',
                'user.delete.own',
                'webmail.access',
                'webmail.send',
                // API key management — default: users can manage their own keys.
                // Remove these two lines to lock the API to admin-provisioned keys only.
                'api.key.create',
                'api.key.revoke',
                // Chat
                'chat.view',
                'chat.post',
                'chat.delete.own',
                'chat.pm',
                // Imageboard
                'board.view',
                'board.post',
            ],
            'MOD' => [
                'news.view',
                'comment.post',
                'comment.hide.own',
                'comment.hide.any',
                'comment.delete.own',
                'comment.delete.any',
                'comment.flag',
                'user.view.public',
                'user.edit.own',
                'user.delete.own',
                'admin.access',
                'admin.comments',
                // Moderators keep every moderation power (comment.*, chat.moderate,
                // board.moderate, admin.comments) but none of the admin.config.* grants:
                // configuring captcha, users, chat, the imageboard and mail is for admins
                // only. The controllers check these levers individually as well.
                'api.key.create',
                'api.key.revoke',
                // Chat
                'chat.view',
                'chat.post',
                'chat.delete.own',
                'chat.delete.any',
                'chat.moderate',
                // Imageboard
                'board.view',
                'board.post',
                'board.moderate',
            ],
        ],
    ],
];
