-- MesaLista · permisos de la base de datos (lo ejecutan install.sh y restore.sh
-- como postgres, conectado a la base de datos mesalista).
--
-- Dos roles:
--   mesalista_owner  dueño de las tablas, la vista y los procedimientos; no
--                    puede iniciar sesión. Con él se carga esquema.sql.
--   mesalista        el de la API (config.php): solo lee y escribe datos. No
--                    puede crear, cambiar ni borrar tablas.

GRANT CONNECT ON DATABASE mesalista TO mesalista;
GRANT USAGE ON SCHEMA public TO mesalista;
REVOKE CREATE ON SCHEMA public FROM PUBLIC;
GRANT SELECT, INSERT, UPDATE, DELETE ON ALL TABLES IN SCHEMA public TO mesalista;
GRANT USAGE, SELECT ON ALL SEQUENCES IN SCHEMA public TO mesalista;
GRANT EXECUTE ON ALL FUNCTIONS IN SCHEMA public TO mesalista;
GRANT EXECUTE ON ALL PROCEDURES IN SCHEMA public TO mesalista;
ALTER DATABASE mesalista SET timezone TO 'Europe/Madrid';
