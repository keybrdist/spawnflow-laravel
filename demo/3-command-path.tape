# SpawnFlow — the 3-command path, filmed.
# Prereq: a fresh Laravel app with a `posts` migration run (see demo/setup.sh),
# executed from that app's directory:  vhs path/to/3-command-path.tape
Output "3-command-path.gif"

Set FontSize 16
Set Width 1280
Set Height 720
Set Theme "Catppuccin Latte"
Set TypingSpeed 35ms
Set Shell zsh
Set Padding 24

Type "echo 'From an existing table to a permission-aware resource — 3 commands:'"
Enter
Sleep 2s

Type "composer require spawnflow/spawnflow-laravel"
Enter
Sleep 50s

Type "php artisan spawnflow:install"
Enter
Sleep 12s

Type "php artisan spawnflow:resource Post --generate"
Enter
Sleep 10s

Type "cat app/Spawnflow/PostFields.php"
Enter
Sleep 8s

Type "echo 'Done — self-registered, introspected from the real table. One declaration: schema, validation, permissions.'"
Enter
Sleep 5s
