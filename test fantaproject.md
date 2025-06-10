php artisan migrate:fresh

php artisan teams:set-active-league --target-season-start-year=2023 --league-code=SA
php artisan teams:set-active-league --target-season-start-year=2024 --league-code=SA

php artisan teams:scrape-standings "https://fbref.com/it/comp/11/2021-2022/Statistiche-di-Serie-A-2021-2022" --season=2021 --league="Serie A"
php artisan teams:scrape-standings "https://fbref.com/it/comp/11/Statistiche-di-Serie-A" --season=2023 --league="Serie A"

php artisan teams:scrape-standings "https://fbref.com/it/comp/18/2021-2022/Statistiche-di-Serie-B-2021-2022" --season=2021 --league="Serie B"
php artisan teams:scrape-standings "https://fbref.com/it/comp/18/2021-2022/Statistiche-di-Serie-B-2021-2022" --season=2021 --league="Serie B" --create-missing-teams=true --league-code=SB