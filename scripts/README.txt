Cron рекомендации:

- Ежечасно:
  php /srv/pulse/scripts/auto_close_registration.php | cat
  php /srv/pulse/scripts/auto_mark_no_show.php | cat

- Ежедневно:
  php /srv/pulse/scripts/auto_finalize_results.php | cat

