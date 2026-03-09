# Kreative — Bug & Fix Tracker

Dashboard para equipos de I+D. Lee CSVs exportados desde Google Sheets
y persiste toda la información en `data.json`.

## Archivos

```
kreative-tracker/
├── index.html   ← Dashboard (abrir en el navegador)
├── api.php      ← Backend PHP
├── data.json    ← Se crea automáticamente al subir el primer CSV
└── README.md
```

## Setup en servidor PHP local

### Opción A — PHP built-in server (recomendado para desarrollo)
```bash
cd kreative-tracker/
php -S localhost:8080
```
Luego abrir: http://localhost:8080

### Opción B — XAMPP / WAMP / Laragon
Copiar la carpeta dentro de `htdocs/` o `www/` y abrir:
http://localhost/kreative-tracker/

### Opción C — Servidor de producción
Subir los archivos al hosting PHP. Verificar que la carpeta
tenga permisos de escritura (chmod 755 o 775).

## Flujo de uso diario

1. En Google Sheets → Archivo → Descargar → CSV (.csv)
2. Abrir el dashboard
3. Click en "Subir CSV" (sidebar o zona central)
4. Seleccionar el proyecto existente O crear uno nuevo
5. El sistema parsea el CSV, detecta ítems nuevos vs actualizados
6. Navegar entre cargas usando la barra de sesiones

## Gestión de múltiples proyectos

- Cada proyecto tiene su propio historial de cargas
- Podés comparar cargas del mismo proyecto (ver evolución)
- En vista "Todas las cargas": los ítems con mismo ID muestran
  la versión más reciente (la última carga gana)

## Formato CSV soportado

El sistema detecta automáticamente las columnas buscando:
- Bug/Fix (tipo)
- Descripción / Descripcion
- Sección / Seccion
- Detectado por
- Responsable
- Estado tester / Estado Responsable
- Prioridad
- Fecha de Reporte / Fecha de Resolución
- Comentarios / Tarea

## data.json — estructura

```json
{
  "projects": {
    "kreative-gen16-abc123": {
      "id": "kreative-gen16-abc123",
      "name": "Kreative Gen 16",
      "created_at": "2026-01-20T10:00:00+00:00",
      "updated_at": "2026-01-24T15:30:00+00:00",
      "sessions": [
        {
          "id": "session-20260120-100000",
          "filename": "Dashboard_Kreative_Gen16.csv",
          "uploaded_at": "2026-01-20T10:00:00+00:00",
          "item_count": 22,
          "new_count": 22,
          "updated_count": 0,
          "items": [ ... ]
        }
      ]
    }
  }
}
```

## Notas

- El `data.json` se puede hacer backup fácilmente (es texto plano)
- Para resetear un proyecto: eliminarlo desde el dashboard
- Para migrar: copiar el `data.json` al nuevo servidor
