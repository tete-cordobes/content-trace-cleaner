# Content Trace Cleaner

Plugin de WordPress para limpiar rastros de contenido generado por IA (LLMs) como ChatGPT, Claude, Bard y otros modelos de lenguaje.

![WordPress](https://img.shields.io/badge/WordPress-5.0%2B-blue)
![PHP](https://img.shields.io/badge/PHP-7.4%2B-purple)
![License](https://img.shields.io/badge/License-GPL%20v2-green)
![Version](https://img.shields.io/badge/Version-1.0.0-orange)

## Descripción

Cuando copias y pegas contenido generado por ChatGPT, Claude u otros LLMs en WordPress, estos suelen dejar rastros ocultos: atributos HTML especiales, caracteres Unicode invisibles y referencias de contenido. **Content Trace Cleaner** detecta y elimina automáticamente estos rastros para mantener tu contenido limpio y profesional.

## Características Principales

### Limpieza de Contenido
- **36 atributos HTML** específicos de LLMs (`data-llm`, `data-pm-slice`, `data-message-id`, etc.)
- **23 tipos de caracteres Unicode invisibles** usados como "marcas de agua" por LLMs
- **Referencias de contenido** como `[oaicite:...]` de OpenAI
- **Parámetros UTM** de tracking en URLs

### Control de Caché para Bots
- Detecta automáticamente bots y crawlers de IA
- Desactiva caché para que reciban contenido actualizado
- Compatible con 9+ plugins de caché populares

### Interfaz Intuitiva
- Panel de administración completo
- Procesamiento por lotes (configurable 1-100 posts)
- Análisis previo sin modificar contenido
- Sistema de logs detallado

## Instalación

1. Descarga el plugin
2. Sube la carpeta `content-trace-cleaner` a `/wp-content/plugins/`
3. Activa el plugin desde el panel de WordPress
4. Accede a **Content Trace Cleaner** en el menú de administración

## Uso

### Limpieza Manual
1. Ve a **Content Trace Cleaner** en el menú de admin
2. Haz clic en **"Analizar todo el contenido"** para ver qué se limpiará
3. Haz clic en **"Limpiar todo el contenido"** para ejecutar
4. Observa el progreso en tiempo real

### Limpieza Automática
1. Ve a **Configuración**
2. Activa **"Limpieza automática en cada edición"**
3. El plugin limpiará automáticamente cada vez que guardes un post

## Configuración

| Opción | Descripción |
|--------|-------------|
| Limpieza automática | Limpia contenido al guardar posts |
| Desactivar caché para bots | Sirve contenido fresco a crawlers de IA |
| Tamaño de lote | Posts procesados por solicitud (1-100) |
| Limpiar atributos | Elimina atributos HTML de LLMs |
| Limpiar Unicode | Elimina caracteres invisibles |
| Limpiar referencias | Elimina marcas `[oaicite:...]` |
| Limpiar UTM | Elimina parámetros de tracking |

## Atributos HTML que Elimina

```
data-start, data-end, data-is-last-node, data-is-only-node
data-llm, data-pm-slice, data-llm-id, data-llm-trace
data-original-text, data-source-text, data-highlight, data-entity
data-mention, data-offset-key, data-message-id, data-sender
data-role, data-token-index, data-model, data-render-timestamp
data-update-timestamp, data-confidence, data-temperature, data-seed
data-step, data-lang, data-format, data-annotation, data-reference
data-version, data-error, data-stream-id, data-chunk, data-context-id
data-user-id, data-ui-state
```

## Compatibilidad con Plugins de Caché

- LiteSpeed Cache
- WP Rocket
- W3 Total Cache
- WP Super Cache
- NitroPack
- Cache Enabler
- Comet Cache
- WP Fastest Cache
- Autoptimize

## Compatibilidad con Page Builders

El plugin preserva correctamente los bloques de:

- Gutenberg (editor nativo)
- Elementor
- Divi Builder
- Beaver Builder
- Visual Composer / WPBakery
- Oxygen Builder
- Fusion Builder (Avada)

## Requisitos

- WordPress 5.0 o superior
- PHP 7.4 o superior

## Hooks para Desarrolladores

```php
// Personalizar atributos a eliminar
add_filter('content_trace_cleaner_attributes', function($attrs) {
    $attrs[] = 'data-custom-attribute';
    return $attrs;
});

// Personalizar mapa de Unicode
add_filter('content_trace_cleaner_unicode_map', function($map) {
    // Añadir caracteres personalizados
    return $map;
});
```

## Changelog

### 1.0.0
- Versión inicial
- Limpieza de 36 atributos HTML de LLMs
- Limpieza de 23 tipos de Unicode invisible
- Eliminación de referencias de contenido
- Eliminación de parámetros UTM
- Control de caché para bots
- Interfaz de administración completa
- Sistema de logging

## Licencia

GPL v2 o superior

## Autor

Desarrollado para mantener el contenido de WordPress limpio de rastros de IA.

---

**Sin telemetría. Sin tracking. Solo limpieza.**
