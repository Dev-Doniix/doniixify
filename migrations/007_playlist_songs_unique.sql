DELETE p1 FROM playlist_songs p1
INNER JOIN playlist_songs p2
WHERE p1.playlist_id = p2.playlist_id
  AND p1.song_id = p2.song_id
  AND p1.position > p2.position;

ALTER TABLE playlist_songs
    ADD UNIQUE KEY uniq_playlist_song (playlist_id, song_id);
