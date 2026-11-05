# MesaLista · Servidor

Servidor del servicio de reserva de mesas MesaLista: API REST en PHP 8.3 con
Apache y PostgreSQL 16, en una máquina virtual de Azure con Ubuntu Server 24.04.
Es una de las tres partes del proyecto:

| Parte | Tecnologías | Cómo se conecta |
|---|---|---|
| **Servidor** (este repositorio) | Apache, PHP 8.3, PostgreSQL 16 | Ofrece la API en `https://<dominio>/api/v1/` |
| Web | HTML, CSS, PHP, JavaScript y Bootstrap | Su PHP llama a la API con un token Bearer |
| App | Con Firebase | Llama a la API con un token Bearer; recibe avisos push por FCM |

Todas las reglas del servicio (disponibilidad, plazos, mesas, QR, pagos…) están
en el servidor: la web y la app solo muestran datos y envían peticiones.

## Estructura

```
bd/
  esquema.sql      Tablas, vista, restricción contra solapamientos y procedimientos
  datos.sql        Restaurante de pruebas: 12 mesas con plano + 2 extra, franjas, parámetros
php/               Lógica de la API (fuera de la raíz web)
publico/           Punto de entrada de la API
despliegue/        Instalación en Azure, Apache, copias de seguridad
pruebas/           Pruebas automáticas de la API
docs/              Contrato de la API (OpenAPI) y guía para la web y la app
```

## Estado

Se construye por fases:

1. **Esquema de PostgreSQL y conexión** (hecha).
2. API `/api/v1` con tokens Bearer.
3. Pruebas automáticas.
4. Avisos push con Firebase Cloud Messaging.
5. Instalación, `openapi.yaml` y guía de integración.

## Base de datos en local

Con PostgreSQL 16 (la base de datos debe pertenecer al usuario de la aplicación,
que crea la extensión `btree_gist`):

```bash
psql -U postgres -c "CREATE ROLE mesalista LOGIN PASSWORD '<contraseña>';"
psql -U postgres -c "CREATE DATABASE mesalista OWNER mesalista ENCODING 'UTF8' TEMPLATE template0;"
psql -U mesalista -d mesalista -f bd/esquema.sql
psql -U mesalista -d mesalista -f bd/datos.sql
```

`esquema.sql` borra y crea todas las tablas.
