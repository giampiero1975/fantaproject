<?php

namespace App\Http\Controllers;

use App\Models\UserLeagueProfile;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth; // Se usi l'autenticazione
use Illuminate\Support\Facades\Log;

class UserLeagueProfileController extends Controller
{
    /**
     * Mostra il form per modificare il profilo della lega.
     * Per ora, assumiamo un singolo profilo globale o il profilo del primo utente.
     * In futuro, questo sarà legato all'utente autenticato.
     */
    public function edit()
    {
        // Per un'applicazione multi-utente, faresti:
        // $user = Auth::user();
        // $profile = UserLeagueProfile::firstOrCreate(['user_id' => $user->id], [/* valori default se crei */]);
        
        // Per ora, gestiamo un singolo profilo (ID 1 o il primo trovato)
        $profile = UserLeagueProfile::first();
        
        if (!$profile) {
            // Se non esiste nessun profilo, ne creiamo uno con valori di default
            // Questo è utile per il primo avvio dell'applicazione.
            // Potresti voler associare a user_id = 1 se hai un utente admin di default,
            // o lasciare user_id null se l'app è intesa per un singolo utente senza login.
            Log::info('Nessun UserLeagueProfile trovato, ne creo uno di default.');
            $profile = UserLeagueProfile::create([
                'league_name' => 'La Mia Lega Fantacalcio',
                'total_budget' => 500,
                'num_goalkeepers' => 3,
                'num_defenders' => 8,
                'num_midfielders' => 8,
                'num_attackers' => 6,
                'num_participants' => 10,
                'scoring_rules' => json_encode([ // Esempio di regole come JSON
                    'gol_portiere' => 0, // Spesso non si assegna, ma per esempio
                    'gol_difensore' => 4,
                    'gol_centrocampista' => 3.5,
                    'gol_attaccante' => 3,
                    'rigore_segnato' => 3,
                    'rigore_sbagliato' => -3,
                    'rigore_parato' => 3,
                    'autogol' => -2,
                    'assist_standard' => 1,
                    'assist_da_fermo' => 1, // O differenzia
                    'ammonizione' => -0.5,
                    'espulsione' => -1,
                    'gol_subito_portiere' => -1, // Per ogni gol subito dal portiere
                    'imbattibilita_portiere' => 1, // Bonus porta inviolata
                ]),
                // 'user_id' => Auth::id() // Se hai utenti loggati
            ]);
        }
        
        // Assicurati che scoring_rules sia una stringa per il textarea, se non usi il cast 'array' nel modello
        // o se vuoi mostrare il JSON grezzo. Se usi il cast 'array' e vuoi un textarea,
        // dovrai fare json_encode($profile->scoring_rules) nella vista o qui.
        // Per semplicità, se è un array, lo passiamo così, la vista gestirà come mostrarlo.
        
        return view('league.profile_edit', compact('profile'));
    }
    
    /**
     * Aggiorna il profilo della lega nel database.
     */
    public function update(Request $request)
    {
        $validatedData = $request->validate([
            'league_name' => 'required|string|max:255',
            'total_budget' => 'required|integer|min:1',
            'num_goalkeepers' => 'required|integer|min:0|max:10',
            'num_defenders' => 'required|integer|min:0|max:20',
            'num_midfielders' => 'required|integer|min:0|max:20',
            'num_attackers' => 'required|integer|min:0|max:15',
            'num_participants' => 'required|integer|min:2|max:30',
            'scoring_rules' => 'nullable|string', // Accetta una stringa JSON
        ]);
        
        // Per ora, aggiorniamo il primo profilo trovato o quello con ID 1.
        // In un sistema multi-utente: $profile = UserLeagueProfile::where('user_id', Auth::id())->firstOrFail();
        $profile = UserLeagueProfile::first();
        if (!$profile) {
            // Dovrebbe essere stato creato in edit(), ma per sicurezza
            $profile = new UserLeagueProfile();
            // $profile->user_id = Auth::id(); // Se hai utenti
        }
        
        $profile->league_name = $validatedData['league_name'];
        $profile->total_budget = $validatedData['total_budget'];
        $profile->num_goalkeepers = $validatedData['num_goalkeepers'];
        $profile->num_defenders = $validatedData['num_defenders'];
        $profile->num_midfielders = $validatedData['num_midfielders'];
        $profile->num_attackers = $validatedData['num_attackers'];
        $profile->num_participants = $validatedData['num_participants'];
        
        // Gestione scoring_rules: se è una stringa JSON valida, la salva, altrimenti salva null o la stringa grezza.
        // Il cast 'array' nel modello gestirà la conversione.
        $scoringRulesInput = $validatedData['scoring_rules'];
        if ($scoringRulesInput) {
            json_decode($scoringRulesInput);
            if (json_last_error() === JSON_ERROR_NONE) {
                $profile->scoring_rules = $scoringRulesInput; // Salva come stringa JSON
            } else {
                // Se non è JSON valido, potresti voler dare un errore o salvare la stringa così com'è
                // Per ora, se non è JSON valido ma è stato passato, lo salviamo come testo.
                // O potresti aggiungere una validazione custom per JSON.
                $profile->scoring_rules = $scoringRulesInput;
                Log::warning('UserLeagueProfileController@update: scoring_rules non era JSON valido, salvato come testo.', ['data' => $scoringRulesInput]);
            }
        } else {
            $profile->scoring_rules = null; // O un array vuoto json_encode([])
        }
        
        $profile->save();
        
        Log::info('UserLeagueProfileController@update: Profilo lega aggiornato.', ['id' => $profile->id]);
        
        return redirect()->route('league.profile.edit')->with('success', 'Profilo Lega aggiornato con successo!');
    }
}
