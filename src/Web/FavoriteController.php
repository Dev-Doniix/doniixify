<?php

declare(strict_types=1);

namespace Doniixify\Web;

use Doniixify\Database;

final class FavoriteController
{
    public static function toggle(int $songId): void
    {
        if (!Session::isLogged()) {
            http_response_code(401);
            header('Content-Type: application/json');
            echo json_encode(['error' => 'Unauthorized']);
            return;
        }
        $user = Session::user();

        $exists = Database::fetchOne(
            "SELECT 1 FROM stars WHERE user_id = ? AND item_type = 'song' AND item_id = ?",
            [$user['id'], $songId]
        );

        if ($exists !== null) {
            Database::execute(
                "DELETE FROM stars WHERE user_id = ? AND item_type = 'song' AND item_id = ?",
                [$user['id'], $songId]
            );
            $starred = false;
        } else {
            Database::execute(
                "INSERT INTO stars (user_id, item_type, item_id) VALUES (?, 'song', ?)",
                [$user['id'], $songId]
            );
            $starred = true;
        }

        header('Content-Type: application/json');
        echo json_encode(['starred' => $starred]);
    }
}
