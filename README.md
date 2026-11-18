# ASMI — Sistema Completo de Gestión Hospitalaria Multihospitalario

ASMI es un sistema de registro y gestión hospitalaria diseñado para una **red pública multihospitalaria** con conectividad limitada: funciona completo sin servidores (un solo archivo), y cuando se instala con XAMPP sincroniza las citas con una base de datos MySQL mediante su propia API en PHP.

> ASMI es un sistema de información hospitalaria (expediente y gestión), **no un dispositivo médico**.

## Aplicación en línea

- **Entrar a la aplicación ASMI:** https://jeonhokookjamg.github.io/ASMI-/ASMI/
- **Página del proyecto:** https://jeonhokookjamg.github.io/ASMI-/
- En este repositorio, la página del proyecto es `index.html` (raíz) y la aplicación vive en `ASMI/index.html`, con su API en `ASMI/php/` y su base de datos en `ASMI/db/asmi.sql`.

---

## 1. Arquitectura

```
┌──────────────────────────────────────────────────────────────┐
│ PORTAL (index.html) — un solo archivo, offline-first          │
│  Portal Ejecutivo · Portal del Personal · Portal del Paciente │
│  Datos locales (localStorage) + documentos PDF (jsPDF)        │
└───────────────┬──────────────────────────────────────────────┘
                │  Puente opcional y silencioso (feature-detect):
                │  solo si se sirve por HTTP y la API responde al ping
┌───────────────▼──────────────────────────────────────────────┐
│ API (php/api.php + php/config.php) — PHP + PDO                │
│  Prepared statements · Respuestas JSON · Errores consistentes │
│  Endpoints: ping, login, buscarPaciente, citas_listar,        │
│  citas_crear, citas_cancelar, directorio, stats               │
└───────────────┬──────────────────────────────────────────────┘
┌───────────────▼──────────────────────────────────────────────┐
│ BASE DE DATOS (db/asmi.sql) — MySQL / MariaDB                 │
│  hospitales · especialidades · personal (7,776) ·             │
│  pacientes (980) · citas (UNIQUE anti-doble-cita) · recetas   │
└──────────────────────────────────────────────────────────────┘
```

- **Modo offline (file://):** el portal trabaja con su almacenamiento local; cero errores en consola, misma experiencia.
- **Modo servidor (XAMPP):** el puente detecta la API con un `ping`; las citas se crean/leen también vía API, se fusionan sin duplicar (clave médico+fecha+hora) y las cancelaciones locales se propagan al servidor. La base de datos impone además su propio `UNIQUE (medico_nombre, fecha, hora)`: la doble cita es imposible en los dos niveles.
- **Modo contingencia:** el módulo *Bitácora y Respaldo* exporta todos los datos locales en un JSON restaurable en otro equipo.

## 2. Módulos del sistema

| Área | Estado en ASMI |
|---|---|
| Admisión | Recepción y admisión de pacientes (portal del personal) |
| Urgencias / Triage | Sala de espera con Triage de Mánchester (5 niveles, tiempos meta y alertas cuando un paciente rebasa su tiempo de espera) dentro del Centro de Comando |
| Consulta / Citas | **Agenda Única Multihospitalaria**: ocupación global por médico en toda la red; un horario ocupado se bloquea para todos y se sugiere el siguiente libre |
| Expediente | Historial clínico por paciente, con PDF oficial |
| Farmacia | Recetas oficiales en PDF + **Inventario de Farmacia** con entradas, salidas/surtido y alertas de stock mínimo |
| Laboratorio | Órdenes de estudios (los del propio expediente ASMI) y captura de resultados Normal/Alterado |
| Camas / Ocupación | Tablero de ocupación por unidad derivado de los expedientes activos (Centro de Comando) |
| Personal y credenciales | Directorio nacional (7,776 cuentas, hospitales reales), credencial profesional frente + reverso con código de barras |
| Administración | Portal Ejecutivo con control total (solo 4 directivos), solicitudes, altas de personal con candado de rol |
| Reportes / Estadística | Informes en PDF con folio, estadísticas de red y citas por área en el Centro de Comando, endpoint `stats` |
| Bitácora / Auditoría | Registro local de accesos, citas, triage, documentos generados, inventario y laboratorio |
| Respaldo / Exportación | Exportación e importación JSON de todos los datos locales del sistema |
| Documentos | Sistema de diseño documental propio: folio único, código de barras Code39, marca de agua en documentos clínicos, pie con paginación |

## 3. Roles y permisos

- **Dirección Ejecutiva (control total):** únicamente 4 personas — José Ángel Maldonado Guzmán (Director General), Diego Esaú Barrera Rodríguez, Diego Antonio Luna Ponce y Abel Alejandro Reséndiz Gálvez. Solo ellas entran al Portal Ejecutivo, crean personal de cualquier rol y acceden también al Portal del Personal desde un botón flotante.
- **Personal hospitalario:** entra con su usuario institucional; ve el directorio de su red, agenda, pacientes, farmacia, laboratorio e inventario. Nadie más puede crear cuentas de dirección (candado de rol verificado por pruebas).
- **Paciente:** entra con su **NSS exacto** (sin coincidencias parciales) y solo ve su información: citas, receta, historial, hospital, tratamiento y perfil.

## 4. Instalación

Ver **INSTRUCCIONES.txt**: Opción A con XAMPP (copiar carpeta a `htdocs`, importar `db/asmi.sql` en la base `asmi_medical`, abrir `http://localhost/ASMI/`) y Opción B solo-HTML offline.

## 5. Qué hace a ASMI único en su clase (diferenciadores internacionales)

Referentes mundiales del sector: **Epic** y **Oracle Health (antes Cerner)** dominan los hospitales grandes de EE. UU. con suites integradas que exigen infraestructura de servidor, licencias costosas por usuario y equipos de implantación; **OpenMRS** y **DHIS2** son las plataformas abiertas de referencia para países de bajos recursos — potentes, pero requieren servidores, implementadores y configuración por país, y ninguno trae de origen una agenda única para toda una red hospitalaria ni un centro de comando con triage integrado. El **Capacity Command Center del hospital Johns Hopkins** (Baltimore) demostró que un centro de comando con visibilidad de capacidad en tiempo real mejora la ocupación y los traslados (Kane et al., 2019), pero fue construido con tecnología comercial (GE Healthcare) alimentada por 14 sistemas distintos.

ASMI toma esas lecciones y las lleva al contexto contrario: una red pública con **baja conectividad y sin presupuesto de licencias**. Sus ventajas concretas y verificables:

1. **Agenda Única Multihospitalaria con anti-doble-cita global.** La ocupación de cada médico se calcula sobre TODA la red: si un médico ya tiene una cita a una hora en cualquier hospital, ese horario se bloquea en todos lados y el sistema sugiere el siguiente hueco libre. En modo servidor, la base de datos lo garantiza además con una clave UNIQUE. Ni OpenMRS ni DHIS2 ofrecen esto de origen para una red completa.
2. **Centro de Comando con triage integrado.** Inspirado en el Capacity Command Center de Johns Hopkins y en el Triage de Mánchester: KPIs de la red en vivo, capacidad por médico con siguiente horario libre, sala de urgencias con 5 niveles y tiempos meta, alertas cuando un paciente rebasa su tiempo de espera, ocupación de camas y citas por área — todo dentro del mismo archivo, sin integrar 14 sistemas.
3. **Offline-first real: un solo archivo, sin servidores ni licencias.** ASMI completo (portales, agenda, triage, documentos) funciona abriendo un archivo HTML, en equipos modestos y sin internet. Costo de licencias: cero. Epic u Oracle Health no pueden operar así; OpenMRS/DHIS2 necesitan servidor e implementador.
4. **Instalación en minutos y sistema completo opcional.** Doble clic y funciona; con XAMPP (copiar carpeta + importar la base) se suma la API PHP/MySQL con sincronización de citas, sin cambiar la experiencia del usuario.
5. **Visibilidad de capacidad de toda la red en tiempo real.** Cualquier unidad ve médicos, horarios libres, camas ocupadas y urgencias de la red completa: la información para derivar pacientes deja de depender de llamadas telefónicas.
6. **Documentos formales con folio y código de barras.** Cada receta, historial, comprobante de citas, informe, solicitud y credencial sale con diseño oficial uniforme, folio único verificable y código de barras Code39 — trazabilidad documental que en otros sistemas abiertos requiere módulos adicionales.
7. **Modo de contingencia con respaldo portable.** Ante una falla de equipo o de red, los datos se exportan en un JSON y se restauran en otro equipo en minutos: la operación no se detiene.

**Honestidad técnica — lo que ASMI todavía no es:** no afirmamos superioridad clínica sobre ningún sistema. ASMI **no cuenta hoy** con interoperabilidad HL7 FHIR, ni con certificaciones NOM-024, ISO/IEC 27001 o ISO 27799: está alineado con esos marcos y su hoja de ruta los contempla como fases futuras (firma electrónica avanzada, farmacia/laboratorio/facturación ampliados, interoperabilidad HL7 FHIR R4 y aplicación móvil). Las contraseñas heredadas de la base de demostración se almacenan en texto plano (limitación conocida del despliegue local); la API ya acepta contraseñas con `password_hash()` para una migración futura, y en producción el acceso debe endurecerse con autenticación de servidor y HTTPS.

## 6. Referencias

- Kane EM, Scheulen JJ, Püttgen A, et al. *A Quality Improvement Initiative: Using Lean Methodology to Develop a Health System's Capacity Command Center.* The Joint Commission Journal on Quality and Patient Safety. 2019;45(11). DOI: 10.1016/j.jcjq.2018.11.006.
- Manchester Triage System (sistema de clasificación de urgencias en 5 niveles), referencia del módulo de triage integrado.
- OpenMRS y DHIS2: plataformas abiertas de referencia para sistemas de información en salud en entornos de bajos recursos (documentación pública de ambos proyectos).
- Epic Systems y Oracle Health: suites hospitalarias comerciales de referencia en el mercado de EE. UU. (material público de los fabricantes).

---
*ASMI — Sistema de Gestión Hospitalaria Multihospitalario. Ronda 2: sistema completo (portal + API + base de datos), diseño documental oficial, credencial profesional y módulos de inventario, laboratorio, bitácora y respaldo.*


---

## Ronda 3 — Seguridad, credenciales verificables, inteligencia operativa y abasto automático

Todo lo nuevo vive en un bloque final del portal (`ASMI_V4`, versión 4.0-ronda3) que envuelve funciones existentes sin reescribir el núcleo, más endpoints nuevos en `php/api.php` y tablas nuevas en `db/asmi.sql`. Detalle completo y evidencias: `informe-ronda3.md`. Capturas: `capturas-ronda3/`.

### Seguridad y recuperación (A, B)
- Barrido de ejemplos con datos reales: eliminadas las contraseñas reales de las tarjetas del equipo ejecutivo; los placeholders quedan genéricos («Usuario asignado», «Contraseña asignada», «Escribe tu NSS», «tu-correo@ejemplo.com»).
- Campo `correo_personal` en `personal` (NULL por defecto; nadie lo trae precargado) + endpoints `recuperar_solicitar` / `recuperar_aprobar`. Al aprobar, el servidor genera la temporal, actualiza la cuenta y la envía con `mail()` al correo institucional y al personal; marca `envio='enviado'` solo si `mail()` aceptó todos los destinos. En modo local (file://) la solicitud y la aprobación viven en `localStorage` (`asmi_recuperaciones_v1`, `asmi_seguridad_v1`) con estado «pendiente de envío al sincronizar»; el puente la procesa cuando la API responde. La temporal activa `debeCambiar`: al siguiente ingreso se bloquea el portal hasta crear una contraseña nueva.
- Solo los 4 usuarios de `ASMI_EJECUTIVOS` pueden aprobar recuperaciones y renovaciones (validado en portal y en API).

### Credenciales verificables (D, E, F)
- Registro local `asmi_credenciales_v1` por usuario: folio, versión, emisión y vencimiento (emisión = hoy, vencimiento = +1 año). Estado calculado: Vigente / Por vencer (≤30 días) / Vencida / Renovada — Reemplazada.
- QR real con **qrcode-generator 1.4.4** (Kazuhiko Arase, licencia MIT) incrustado, sin CDN. Codifica `ASMI_QR_BASE + '#verificar=' + folio`, con `ASMI_QR_BASE = 'https://jeonhokookjamg.github.io/ASMI-/ASMI/'` como única constante configurable al inicio del bloque v4. El QR va en el reverso de la credencial (pantalla y PDF) y en los documentos principales (vía el cierre oficial; si el contenido ya llena la página, no fuerza un salto). Al abrir `#verificar=<FOLIO>` se muestra la verificación pública sin login. Tabla BD espejo: `credenciales`; endpoints `credencial_verificar` y `credencial_estado`.
- Renovación: el usuario solicita (`asmi_renovaciones_v1` + bandeja de solicitudes); solo un ejecutivo autoriza; se versiona el folio (`-V2`, `-V3`…), la anterior queda «Reemplazada» y todo entra a bitácora.

### Gemelo Predictivo de la Red ASMI (H) — innovación insignia
Simulador prescriptivo dentro del Centro de Comando, 100% con datos del propio sistema: predice la saturación del día por médico/área/hora (agenda única + llegadas de triage), permite simular «¿qué pasaría si…?» (agregar un médico, mover N consultas, cerrar un consultorio) sin tocar la agenda, y recomienda reacomodos con botón «Aplicar sugerencia» que mueve las citas reales (siguiente horario libre del médico destino) y lo registra en bitácora. API de pruebas: `ASMI_V4.gemelo`.

### ASMI IA — Inteligencia Operativa (I)
Motor local de reglas y estadística (sin APIs externas, sin chat): interacciones y duplicidades de recetas (tabla local; alerta ANTES de emitir el PDF), sugerencia de nivel Manchester por palabras clave en el motivo (el personal confirma), resumen del paciente en un clic exportable a PDF, predicción de agotamiento de inventario por salidas registradas, y vigilancia de credenciales por vencer y médicos saturados. Panel de tarjetas accionables (Aplicar/Ver/Ignorar) en portal de personal y ejecutivo; las acciones e ignorados quedan en bitácora. API: `ASMI_V4.ia`.

### Quirófanos y Cirugías (J)
Salas por hospital (`CLAVE-QX-1..3`) con agenda anti-conflicto por intervalos (sala y cirujano), programación desde el expediente real, etapas Programada → Preparación → En curso → Recuperación → Terminada (+Cancelada) con hora y responsable, checklist preoperatorio obligatorio para entrar a «En curso» (con aviso de alertas de la IA), tablero en vivo en el módulo y en el Centro de Comando, avance visible para el paciente en su portal y PDF oficial de programación. Datos en `asmi_cirugias_v1`; tabla BD `cirugias`. API: `ASMI_V4.quirofanos`.

### Farmacia Autónoma (K2)
Existencias por hospital en tiempo real (`asmi_farmacia_v1`): cada receta emitida descuenta 1 unidad sola en ese hospital; el mínimo es configurable por medicamento; al cruzarlo se genera la alerta (Farmacia + ASMI IA) y la **solicitud automática de reabasto** con cantidad sugerida por la predicción de consumo. El **Portal de Abasto** atiende el ciclo: Generada → Aprobada/En tránsito → Recibida; al registrar la llegada (cantidad, lote, fecha) las existencias suben solas y todo queda en bitácora. Si al recetar el stock está en/bajo el mínimo, el médico ve el aviso sin que se bloquee la receta. Tablas BD espejo: `inventario_por_hospital`, `solicitudes_abasto`. API: `ASMI_V4.farmacia`.

### Textos de cara al usuario (K)
La interfaz y los PDF solo dicen lo que cada usuario necesita para actuar; la mecánica interna (agenda de red, sincronización, módulos) vive únicamente en este README y en los informes. Lista de textos retirados/simplificados en `informe-ronda3.md`.

### Datos institucionales (J/K1)
Los 70 hospitales corresponden a hospitales públicos reales de México (nombre y clasificación verificados contra directorios y páginas institucionales). Directores Generales con fuente pública verificable actualizados en portal y BD: Hospital General de México «Dr. Eduardo Liceaga», INER, Hospital Civil de Guadalajara y Hospital General «Dr. Manuel Gea González» (fuentes en `informe-ronda3.md`). Los demás directores quedan con nombre temporal de proyecto, marcados «pendiente de verificación» en el informe. Las 7,776 cuentas de personal son usuarios semilla del sistema, no personas públicas.

### Pruebas
`hidden/tests/test-ronda3.js` extiende la suite (recuperación E2E, decodificación QR con jsQR, renovación con rechazo a no-admin, Gemelo, IA, quirófanos con doble reserva rechazada, y el ciclo completo de Farmacia Autónoma 20→10→5→+30→35). Suite total: unitarias + E2E + permisos + documentos + módulos + ronda 3, todo verde sobre el archivo final.
