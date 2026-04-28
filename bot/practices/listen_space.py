import datetime
import logging
import os

from telegram import InlineKeyboardButton, InlineKeyboardMarkup, Update, WebAppInfo
from telegram.ext import (
    Application,
    CallbackQueryHandler,
    CommandHandler,
    ContextTypes,
    ConversationHandler,
    MessageHandler,
    filters,
)

from api_client import bot_auth, get_reminder_users, journal_url, save_entry
from practices.base import BasePractice

logger = logging.getLogger(__name__)

REMINDER_HOUR = int(os.environ.get('REMINDER_HOUR', '7'))  # UTC

# State constants — диапазон 100–199
LS_HOME            = 100
LS_CHOOSE_DURATION = 101
LS_WAITING_DONE    = 102
LS_ASK_PLACE       = 103
LS_ASK_FRONT       = 104
LS_ASK_RIGHT       = 105
LS_ASK_BACK        = 106
LS_ASK_LEFT        = 107
LS_ASK_LIKED       = 108
LS_ASK_DISLIKED    = 109
LS_ASK_STORY       = 110


# ── Keyboards ─────────────────────────────────────────────────────────────────

def _kb_durations() -> InlineKeyboardMarkup:
    return InlineKeyboardMarkup([[
        InlineKeyboardButton('5 мин',  callback_data='dur_5'),
        InlineKeyboardButton('10 мин', callback_data='dur_10'),
        InlineKeyboardButton('15 мин', callback_data='dur_15'),
        InlineKeyboardButton('20 мин', callback_data='dur_20'),
    ]])

def _kb_done() -> InlineKeyboardMarkup:
    return InlineKeyboardMarkup([[
        InlineKeyboardButton('✅ Готово', callback_data='done'),
    ]])

def _kb_skip() -> InlineKeyboardMarkup:
    return InlineKeyboardMarkup([[
        InlineKeyboardButton('⏭ Пропустить', callback_data='skip'),
    ]])

def _is_skip(text: str) -> bool:
    return text.strip().lower() in ('⏭ пропустить', 'пропустить', '/skip')


# ── Payload builder ───────────────────────────────────────────────────────────

def _build_payload(data: dict) -> dict:
    return {
        'practiceId':  'listen-space',
        'durationMin': data.get('duration', 0),
        'place':       data.get('place', ''),
        'sounds': {
            'front': data.get('front', ''),
            'right': data.get('right', ''),
            'back':  data.get('back',  ''),
            'left':  data.get('left',  ''),
        },
        'liked':    data.get('liked',    ''),
        'disliked': data.get('disliked', ''),
        'notes':    data.get('story',    ''),
    }


# ── Shared helpers ────────────────────────────────────────────────────────────

async def _collect(key: str, update: Update, context: ContextTypes.DEFAULT_TYPE) -> str:
    if update.callback_query:
        await update.callback_query.answer()
        return ''
    text = update.message.text
    return '' if _is_skip(text) else text


async def _finish(update: Update, context: ContextTypes.DEFAULT_TYPE) -> int:
    user    = update.effective_user
    chat_id = update.effective_chat.id
    data    = context.user_data

    try:
        if not data.get('token'):
            auth = await bot_auth(user.id, user.full_name)
            if auth.get('token'):
                data['token'] = auth['token']

        await save_entry(data.get('token', ''), _build_payload(data))

        d      = data.get('duration', '?')
        place  = data.get('place', '')
        sounds = []
        for key, arrow in [('front', '⬆️'), ('right', '➡️'), ('back', '⬇️'), ('left', '⬅️')]:
            v = data.get(key, '').strip()
            if v:
                sounds.append(f'  {arrow} {v}')

        text = '✦ <b>Запись сохранена!</b>\n\n'
        text += f'🎧 Слушание пространства · {d} мин'
        text += '\n📷 В дневнике доступна генерация фотоотчёта.'
        if place:
            text += f'\n📍 {place}'
        if sounds:
            text += '\n\n🔊 <b>Звуки:</b>\n' + '\n'.join(sounds)
        if data.get('liked'):
            text += f'\n\n❤️ {data["liked"]}'
        if data.get('disliked'):
            text += f'\n\n💔 {data["disliked"]}'
        if data.get('story'):
            text += f'\n\n📖 {data["story"]}'

        for key in ('duration', 'place', 'front', 'right', 'back', 'left', 'liked', 'disliked', 'story'):
            data.pop(key, None)
        data['has_practices'] = True

        url = await journal_url(user.id, user.full_name)
        await context.bot.send_message(
            chat_id, text, parse_mode='HTML',
            reply_markup=InlineKeyboardMarkup([
                [InlineKeyboardButton('📖 Перейти в дневник', web_app=WebAppInfo(url=url))],
                [InlineKeyboardButton('🎧 Новая практика', callback_data='new_practice')],
            ]),
        )
    except Exception as e:
        logger.error('listen_space finish: %s', e)
        try:
            await context.bot.send_message(chat_id, '✦ Практика завершена. Запись сохранена.')
        except Exception:
            pass

    return ConversationHandler.END


# ── Handlers ──────────────────────────────────────────────────────────────────

async def _cmd_start(update: Update, context: ContextTypes.DEFAULT_TYPE) -> int:
    user = update.effective_user
    auth = await bot_auth(user.id, user.full_name)
    if auth.get('token'):
        context.user_data['token'] = auth['token']
        context.user_data['has_practices'] = auth.get('has_entries', False)
    has_practices = context.user_data.get('has_practices', False)
    buttons = [[InlineKeyboardButton('🎧 Начать практику', callback_data='new_practice')]]
    if has_practices:
        url = await journal_url(user.id, user.full_name)
        buttons.append([InlineKeyboardButton('📖 Открыть дневник', web_app=WebAppInfo(url=url))])
    await update.message.reply_text(
        '🎧 <b>Квантовое Ухо</b>\n\nПрактика глубокого слушания пространства.',
        parse_mode='HTML',
        reply_markup=InlineKeyboardMarkup(buttons),
    )
    return LS_HOME


async def _on_home(update: Update, context: ContextTypes.DEFAULT_TYPE) -> int:
    if update.message.text == '📖 Открыть дневник':
        url = await journal_url(update.effective_user.id, update.effective_user.full_name)
        await update.message.reply_text(
            'Открывайте дневник:',
            reply_markup=InlineKeyboardMarkup([[
                InlineKeyboardButton('Открыть дневник 📖', web_app=WebAppInfo(url=url))
            ]]),
        )
        return LS_HOME
    await update.message.reply_text(
        '🎧 <b>Слушание пространства</b>\n\n'
        'Займите удобное положение, сделайте три глубоких вдоха и закройте глаза.\n'
        'Слушайте пространство без попытки оценить происходящее.\n\n'
        'Выберите длительность практики:',
        parse_mode='HTML',
        reply_markup=_kb_durations(),
    )
    return LS_CHOOSE_DURATION


async def _on_new_practice(update: Update, context: ContextTypes.DEFAULT_TYPE) -> int:
    query = update.callback_query
    await query.answer()
    await query.message.reply_text(
        '🎧 <b>Слушание пространства</b>\n\n'
        'Займите удобное положение, сделайте три глубоких вдоха и закройте глаза.\n'
        'Слушайте пространство без попытки оценить происходящее.\n\n'
        'Выберите длительность практики:',
        parse_mode='HTML',
        reply_markup=_kb_durations(),
    )
    return LS_CHOOSE_DURATION


async def _on_choose_duration(update: Update, context: ContextTypes.DEFAULT_TYPE) -> int:
    query = update.callback_query
    await query.answer()
    duration = int(query.data.split('_')[1])
    context.user_data['duration'] = duration
    await query.edit_message_text(
        f'⏱ Установите таймер на <b>{duration} минут</b>.\n\n'
        'Позвольте звукам приходить и уходить, просто замечая их.',
        parse_mode='HTML',
    )
    await context.bot.send_message(
        query.message.chat_id,
        'Нажмите кнопку, когда практика завершится:',
        reply_markup=_kb_done(),
    )
    return LS_WAITING_DONE


async def _on_waiting_done(update: Update, context: ContextTypes.DEFAULT_TYPE) -> int:
    query = update.callback_query
    await query.answer()
    await context.bot.send_message(
        query.message.chat_id,
        '📍 <b>Где вы находились?</b>\n\n<i>Например: парк, квартира, залив, крыша…</i>',
        parse_mode='HTML',
        reply_markup=_kb_skip(),
    )
    return LS_ASK_PLACE


async def _on_ask_place(update: Update, context: ContextTypes.DEFAULT_TYPE) -> int:
    context.user_data['place'] = await _collect('place', update, context)
    await context.bot.send_message(
        update.effective_chat.id,
        '🔊 Звуки <b>⬆️ СПЕРЕДИ</b>\n\n<i>Перечислите через запятую или нажмите «Пропустить»</i>',
        parse_mode='HTML',
        reply_markup=_kb_skip(),
    )
    return LS_ASK_FRONT


async def _on_ask_front(update: Update, context: ContextTypes.DEFAULT_TYPE) -> int:
    context.user_data['front'] = await _collect('front', update, context)
    await context.bot.send_message(
        update.effective_chat.id,
        '🔊 Звуки <b>➡️ СПРАВА</b>\n\n<i>Перечислите через запятую или нажмите «Пропустить»</i>',
        parse_mode='HTML',
        reply_markup=_kb_skip(),
    )
    return LS_ASK_RIGHT


async def _on_ask_right(update: Update, context: ContextTypes.DEFAULT_TYPE) -> int:
    context.user_data['right'] = await _collect('right', update, context)
    await context.bot.send_message(
        update.effective_chat.id,
        '🔊 Звуки <b>⬇️ СЗАДИ</b>\n\n<i>Перечислите через запятую или нажмите «Пропустить»</i>',
        parse_mode='HTML',
        reply_markup=_kb_skip(),
    )
    return LS_ASK_BACK


async def _on_ask_back(update: Update, context: ContextTypes.DEFAULT_TYPE) -> int:
    context.user_data['back'] = await _collect('back', update, context)
    await context.bot.send_message(
        update.effective_chat.id,
        '🔊 Звуки <b>⬅️ СЛЕВА</b>\n\n<i>Перечислите через запятую или нажмите «Пропустить»</i>',
        parse_mode='HTML',
        reply_markup=_kb_skip(),
    )
    return LS_ASK_LEFT


async def _on_ask_left(update: Update, context: ContextTypes.DEFAULT_TYPE) -> int:
    context.user_data['left'] = await _collect('left', update, context)
    await context.bot.send_message(
        update.effective_chat.id,
        '❤️ <b>Что понравилось или привлекло внимание?</b>\n\n<i>Звуки, качества, ощущения…</i>',
        parse_mode='HTML',
        reply_markup=_kb_skip(),
    )
    return LS_ASK_LIKED


async def _on_ask_liked(update: Update, context: ContextTypes.DEFAULT_TYPE) -> int:
    context.user_data['liked'] = await _collect('liked', update, context)
    await context.bot.send_message(
        update.effective_chat.id,
        '💔 <b>Что мешало или казалось лишним?</b>',
        parse_mode='HTML',
        reply_markup=_kb_skip(),
    )
    return LS_ASK_DISLIKED


async def _on_ask_disliked(update: Update, context: ContextTypes.DEFAULT_TYPE) -> int:
    context.user_data['disliked'] = await _collect('disliked', update, context)
    await context.bot.send_message(
        update.effective_chat.id,
        '📖 <b>Опишите историю, которую вы услышали.</b>\n\n'
        '<i>Какой образ, сюжет или настроение возникло из звуков?</i>',
        parse_mode='HTML',
        reply_markup=_kb_skip(),
    )
    return LS_ASK_STORY


async def _on_ask_story(update: Update, context: ContextTypes.DEFAULT_TYPE) -> int:
    context.user_data['story'] = await _collect('story', update, context)
    return await _finish(update, context)


# ── Scheduler ─────────────────────────────────────────────────────────────────

async def _send_daily_reminder(context: ContextTypes.DEFAULT_TYPE) -> None:
    try:
        chat_ids = await get_reminder_users()
    except Exception as e:
        logger.error('reminder_users fetch: %s', e)
        return

    for chat_id in chat_ids:
        try:
            await context.bot.send_message(
                chat_id,
                '🎧 Привет! Сегодня хороший день, чтобы послушать пространство.',
                reply_markup=InlineKeyboardMarkup([[
                    InlineKeyboardButton('🎧 Начать практику', callback_data='new_practice'),
                ]]),
            )
        except Exception as e:
            logger.warning('reminder for %s: %s', chat_id, e)


# ── Practice class ────────────────────────────────────────────────────────────

class ListenSpacePractice(BasePractice):
    practice_id = 'listen-space'

    def get_entry_points(self):
        return [
            CommandHandler('start', _cmd_start),
            MessageHandler(filters.Regex('^📖 Открыть дневник$'), _on_home),
            MessageHandler(filters.Regex('^🎧 Начать практику$'), _on_home),
            CallbackQueryHandler(_on_new_practice, pattern='^new_practice$'),
        ]

    def get_states(self):
        return {
            LS_HOME: [
                CallbackQueryHandler(_on_new_practice, pattern='^new_practice$'),
                MessageHandler(filters.TEXT & ~filters.COMMAND, _on_home),
            ],
            LS_CHOOSE_DURATION: [CallbackQueryHandler(_on_choose_duration, pattern='^dur_')],
            LS_WAITING_DONE:    [CallbackQueryHandler(_on_waiting_done, pattern='^done$')],
            LS_ASK_PLACE:    [
                MessageHandler(filters.TEXT & ~filters.COMMAND, _on_ask_place),
                CallbackQueryHandler(_on_ask_place, pattern='^skip$'),
            ],
            LS_ASK_FRONT:    [
                MessageHandler(filters.TEXT & ~filters.COMMAND, _on_ask_front),
                CallbackQueryHandler(_on_ask_front, pattern='^skip$'),
            ],
            LS_ASK_RIGHT:    [
                MessageHandler(filters.TEXT & ~filters.COMMAND, _on_ask_right),
                CallbackQueryHandler(_on_ask_right, pattern='^skip$'),
            ],
            LS_ASK_BACK:     [
                MessageHandler(filters.TEXT & ~filters.COMMAND, _on_ask_back),
                CallbackQueryHandler(_on_ask_back, pattern='^skip$'),
            ],
            LS_ASK_LEFT:     [
                MessageHandler(filters.TEXT & ~filters.COMMAND, _on_ask_left),
                CallbackQueryHandler(_on_ask_left, pattern='^skip$'),
            ],
            LS_ASK_LIKED:    [
                MessageHandler(filters.TEXT & ~filters.COMMAND, _on_ask_liked),
                CallbackQueryHandler(_on_ask_liked, pattern='^skip$'),
            ],
            LS_ASK_DISLIKED: [
                MessageHandler(filters.TEXT & ~filters.COMMAND, _on_ask_disliked),
                CallbackQueryHandler(_on_ask_disliked, pattern='^skip$'),
            ],
            LS_ASK_STORY:    [
                MessageHandler(filters.TEXT & ~filters.COMMAND, _on_ask_story),
                CallbackQueryHandler(_on_ask_story, pattern='^skip$'),
            ],
        }

    def get_fallbacks(self):
        return [CommandHandler('start', _cmd_start)]

    def register_jobs(self, app: Application) -> None:
        app.job_queue.run_daily(
            _send_daily_reminder,
            time=datetime.time(hour=REMINDER_HOUR, minute=0, tzinfo=datetime.timezone.utc),
        )

    def build_entry(self, data: dict) -> dict:
        return _build_payload(data)
