-- MesaLista · Datos iniciales del restaurante ficticio de pruebas
--
-- 12 mesas en dos zonas (S-1 a S-8 en la sala, T-1 a T-4 en la terraza), de 2
-- a 10 plazas, con su sitio en el plano de cada zona, y 2 mesas extra (E-1,
-- E-2) sin plano que solo sirven para unir; comidas de 13:00 a 16:00 y cenas
-- de 20:00 a 00:00 todos los días; parámetros con los valores iniciales de la
-- tabla «Reglas del servicio».
--
-- Las cuentas (administrador, personal y clientes de prueba) no van aquí
-- porque sus contraseñas se guardan con Argon2id: se crean con
-- php/bin/create_user.php y php/bin/seed_demo.php.
--
-- Uso: psql -d mesalista -f datos.sql   (después de esquema.sql)

-- pos_x / pos_y: centro de la mesa en el lienzo de 1000 × 600 de su zona.
INSERT INTO dining_tables (number, zone, min_capacity, max_capacity, pos_x, pos_y, shape) VALUES
  (1, 'Sala',    1,  2, 110, 110, 'round'),
  (2, 'Sala',    1,  2, 110, 300, 'round'),
  (3, 'Sala',    2,  4, 330, 110, 'rect'),
  (4, 'Sala',    2,  4, 330, 300, 'rect'),
  (5, 'Sala',    2,  4, 330, 490, 'rect'),
  (6, 'Sala',    4,  6, 580, 140, 'round'),
  (7, 'Sala',    5,  8, 580, 410, 'rect'),
  (8, 'Sala',    7, 10, 840, 300, 'rect'),
  (1, 'Terraza', 1,  2, 150, 150, 'round'),
  (2, 'Terraza', 2,  4, 420, 150, 'rect'),
  (3, 'Terraza', 2,  4, 690, 150, 'rect'),
  (4, 'Terraza', 4,  6, 420, 420, 'round');

INSERT INTO dining_tables (number, zone, min_capacity, max_capacity, join_only) VALUES
  (1, 'Extra', 1, 4, true),
  (2, 'Extra', 1, 4, true);

-- Comidas 13:00-16:00 (780-960) y cenas 20:00-00:00 (1200-1440), de lunes a domingo.
INSERT INTO service_slots (weekday, open_min, close_min) VALUES
  (1, 780, 960), (1, 1200, 1440),
  (2, 780, 960), (2, 1200, 1440),
  (3, 780, 960), (3, 1200, 1440),
  (4, 780, 960), (4, 1200, 1440),
  (5, 780, 960), (5, 1200, 1440),
  (6, 780, 960), (6, 1200, 1440),
  (7, 780, 960), (7, 1200, 1440);

INSERT INTO settings (name, value) VALUES
  ('duration_min',          120),  -- duración de reserva
  ('slot_step_min',          30),  -- horas de inicio cada N minutos
  ('min_advance_min',        60),  -- antelación mínima
  ('max_advance_days',       30),  -- antelación máxima
  ('min_party',               1),  -- comensales por reserva
  ('max_party',              10),
  ('max_future_per_client',   3),  -- reservas confirmadas futuras por cliente
  ('change_deadline_h',      24),  -- plazo de modificación y de cancelación
  ('arrival_before_min',     15),  -- ventana de llegada
  ('arrival_after_min',      20),
  ('max_group_party',        40),  -- comensales máximos de una solicitud de grupo (RF-43)
  ('deposit_per_person',      5),  -- señal en euros por persona, 0 = sin señal (RF-44)
  ('deposit_hold_min',       15);  -- minutos que se retiene la mesa para pagar

INSERT INTO menu_options (name) VALUES
  ('Sin gluten'), ('Vegetariano'), ('Vegano'), ('Sin lactosa');
