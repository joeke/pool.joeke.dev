<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;
use Illuminate\Http\RedirectResponse;
use App\Models\Game;
use App\Models\GameType;
use App\Models\User;

class GameController extends Controller
{
    /**
     * Display the new game form.
     */
    public function new(): Response
    {
        $players = User::all();
        $types = GameType::all();

        return Inertia::render('Game/New', [
            'players' => $players,
            'gameTypes' => $types
        ]);
    }

    /**
     * Handle creating a new game.
     *
     * @throws \Illuminate\Validation\ValidationException
     */
    public function store(Request $request): RedirectResponse
    {
        $request->validate([
            'type_id' => 'required',
            'player_id' => 'required',
            'score_goal' => 'required',
        ]);

        $game = Game::create([
            'type_id' => $request->type_id,
            'player_id' => $request->player_id ?? $request->user()->id,
            'opponent_id' => $request->opponent_id,
            'score_goal' => $request->score_goal,
            'start_date' => $request->start_date,
            'end_date' => $request->end_date,
            'created_by' => $request->user()->id
        ]);

        return redirect()->route('game.show', ['id' => $game->id]);
    }

    /**
     * Display the game overview.
     */
    public function overview(): Response
    {
        $games = Game::where('created_by', auth()->user()->id)
            ->with(['player', 'opponent', 'type'])
            ->orderBy('created_at', 'desc')
            ->get();

        // $scores = [];

        // foreach ($games as $game) {
        //     $scores[$game->id] = $this->calculateScores($game);
        // }

        return Inertia::render('Game/Overview', [
            'games' => $games,
            // 'scores' => $scores
        ]);
    }

    /**
     * Show the game form.
     */
    public function show(int $id): Response
    {
        $game = Game::where('id', $id)->with('scores')->first();

        $scores = $this->calculateScores($game);

        $ballsRemaining = 15;

        $lastScore = $game->scores->last();
        if ($lastScore) {
            $ballsRemaining = $lastScore->balls_remaining;
        }

        $game['balls_remaining'] = $ballsRemaining;

        $players = [];
        if ($game->player_id && $game->player_id > 0) {
            $players[$game->player_id] = $this->getPlayerMeta($game->player_id, $scores);
        }
        if ($game->opponent_id && $game->opponent_id > 0) {
            $players[$game->opponent_id] = $this->getPlayerMeta($game->opponent_id, $scores);
        }

        $game['active_player'] = $this->determineActivePlayer($game);

        return Inertia::render('Game/Show', [
            'game' => $game,
            'players' => $players,
            'scores' => $scores
        ]);
    }

    private function calculateScores($game)
    {
        $scores = $game->scores->groupBy('player_id');

        $output = [];

        foreach ($scores as $playerId => $innings) {
            $totalPoints = 0;
            $foulPoints = 0;

            $newInnings = [];

            foreach ($innings as $score) {
                $totalPoints += ($score->points - $score->foul_points);
                $foulPoints += $score->foul_points;
                $newInnings[] = array_merge($score->toArray(), [
                    'total_points' => $totalPoints,
                ]);
            }

            $output[$playerId] = [
                'id' => $playerId,
                'name' => $this->getPlayerName($playerId),
                'total_points' => $totalPoints,
                'foul_points' => $foulPoints,
                'high_run' => $innings->max('points'),
                'ppi' => round($totalPoints / count($innings), 2),
                'innings_count' => count($innings),
                'innings' => $newInnings,
            ];
        }

        return $output;
    }

    private function getPlayerName($id)
    {
        $player = User::where('id', $id)->first();

        if ($player) {
            return $player->name;
        }

        return null;
    }

    private function getPlayerMeta($id, $scores)
    {
        return [
            'id' => $id,
            'name' => $this->getPlayerName($id),
            'total_points' => $scores && isset($scores[$id]) ? $scores[$id]['total_points'] : 0,
        ];
    }

    /**
     * Determine which player is next by finding the player_id for last inning.
     * If no innings, then the default player is the active player.
     * Also check if there is an opponent, otherwise the default player is the active player.
     *
     * @param Game $game
     */
    private function determineActivePlayer(Game $game)
    {
        $activePlayer = $game->player_id;

        $gameScores = $game->scores->toArray();
        $lastInning = end($gameScores);

        if ($lastInning && $lastInning['player_id'] === $game->player_id && $game->opponent_id) {
            $activePlayer = $game->opponent_id;
        }

        return $activePlayer;
    }
}
