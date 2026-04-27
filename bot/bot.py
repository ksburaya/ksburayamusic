import os
import logging
import urllib.parse
import httpx
from dotenv import load_dotenv

load_dotenv()
from telegram import (
    Update, InlineKeyboardButton, InlineKeyboardMarkup,
    ReplyKeyboardMarkup, ReplyKeyboardRemove, WebAppInfo,
)
from telegram.ext import (
    Application, CommandHandler, MessageHandler,
    CallbackQueryHandler, ConversationHandler, ContextTypes, filters,
)

logging.basicConfig(
    format='%(asctime)s - %(name)s - %(levelname)s - %(message)s',
    level=logging.INFO,
)
logger = logging.getLogger(__name__)

BOT_TOKEN    = os.environ['BOT_TOKEN']
WEBHOOK_URL  = os.environ['WEBHOOK_URL']
PORT         = int(os.environ.get('PORT', 8080))
BOT_SECRET   = os.environ.get('BOT_SECRET', '')
API_BASE     = 'https://ksburayamusic.ru/deeplistening/api'
JOURNAL_BASE = 'https://ksburayamusic.ru/deeplistening/journal.html'

# ── FSM states ────────────────────────────────────────────────────────────────

(HOME, CHOOSE_DURATION, WAITING_DONE, ASK_PLACE,
 ASK_FRONT, ASK_RIGHT, ASK_BACK, ASK_LEFT,
 ASK_LIKED, ASK_DISLIKED, ASK_STORY) = range(11)

# ── Keyboards ─────────────────────────────────────────────────────────────────

def kb_durations() -> InlineKeyboardMarkup:
    return InlineKeyboardMarkup([[
        InlineKeyboardButton('5 мин',  callback_data='dur_5'),
        InlineKeyboardButton('10 мин', callback_data='dur_10'),
        InlineKeyboardButton('15 мин', callback_data='dur_15'),
        InlineKeyboardButton('20 мин', callback_data='dur_20'),
    ]])

def kb_done() -> InlineKeyboardMarkup:
    return InlineKeyboardMarkup([[
        InlineKeyboardButton('✅ Готово', callback_data='done'),
    ]])

def kb_skip() -> InlineKeyboardMarkup:
    return InlineKeyboardMarkup([[
        InlineKeyboardButton('⏭ Пропустить', callback_data='skip'),
    ]])

def kb_home(has_practices: bool) -> ReplyKeyboardMarkup:
    buttons = [['🎧 Начать практику']]
    if has_practices:
        buttons.append(['📖 Открыть дневник'])
    return ReplyKeyboardMarkup(buttons, resize_keyboard=True)

def kb_journal(url: str) -> InlineKeyboardMarkup:
    return InlineKeyboardMarkup([[
        InlineKeyboardButton('Открыть дневник 📖', web_app=WebAppInfo(url=url))
    ]])

def is_skip(text: str) -> bool:
    return text.strip().lower() in ('⏭ пропустить', 'пропустить', '/skip')

# ── API helpers ───────────────────────────────────────────────────────────────

async def bot_auth(telegram_id: int, tg_name: str) -> dict:
    """POST /bot-auth.php → {'token': '...', 'has_entries': bool}"""
    try:
        async with httpx.AsyncClient(timeout=10) as client:
            r = await client.post(
                f'{API_BASE}/bot-auth.php',
                json={'telegram_id': telegram_id, 'name': tg_name, 'bot_secret': BOT_SECRET},
            )
            return r.json()
    except Exception as e:
        logger.error('bot_auth: %s', e)
        return {}

async def journal_url(telegram_id: int, tg_name: str) -> str:
    """Возвращает URL журнала с токеном — браузер сразу входит без Mini App initData."""
    auth = await bot_auth(telegram_id, tg_name)
    token = auth.get('token', '')
    if not token:
        return JOURNAL_BASE
    name = urllib.parse.quote(tg_name or str(telegram_id))
    return f'{JOURNAL_BASE}?token={token}&name={name}'

async def save_entry(token: str, data: dict) -> bool:
    if not token:
        logger.error('save_entry: токен отсутствует')
        return False
    try:
        payload = {
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
        async with httpx.AsyncClient(timeout=10) as client:
            r = await client.post(
                f'{API_BASE}/entries.php',
                json=payload,
                headers={'Authorization': f'Bearer {token}'},
            )
            return r.is_success
    except Exception as e:
        logger.error('save_entry: %s', e)
        return False

# ── Shared finish ─────────────────────────────────────────────────────────────

async def finish(update: Update, context: ContextTypes.DEFAULT_TYPE) -> int:
    user    = update.effective_user
    chat_id = update.effective_chat.id
    data    = context.user_data

    try:
        # Refresh token if missing (e.g. after bot restart)
        if not context.user_data.get('token'):
            auth = await bot_auth(user.id, user.full_name)
            if auth.get('token'):
                context.user_data['token'] = auth['token']

        await save_entry(context.user_data.get('token', ''), data)

        d      = data.get('duration', '?')
        place  = data.get('place', '')
        sounds = []
        for key, arrow in [('front','⬆️'), ('right','➡️'), ('back','⬇️'), ('left','⬅️')]:
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

        # Очищаем данные практики
        for key in ('duration', 'place', 'front', 'right', 'back', 'left',
                    'liked', 'disliked', 'story'):
            context.user_data.pop(key, None)
        context.user_data['has_practices'] = True

        url = await journal_url(user.id, user.full_name)
        await context.bot.send_message(
            chat_id,
            text,
            parse_mode='HTML',
            reply_markup=InlineKeyboardMarkup([
                [InlineKeyboardButton('📖 Перейти в дневник', web_app=WebAppInfo(url=url))],
                [InlineKeyboardButton('🎧 Новая практика', callback_data='new_practice')],
            ]),
        )
    except Exception as e:
        logger.error('finish: %s', e)
        try:
            await context.bot.send_message(
                chat_id,
                '✦ Практика завершена. Запись сохранена.',
            )
        except Exception:
            pass

    return ConversationHandler.END

# ── Handlers ──────────────────────────────────────────────────────────────────

async def cmd_start(update: Update, context: ContextTypes.DEFAULT_TYPE) -> int:
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
        '🎧 <b>Квантовое Ухо</b>\n\n'
        'Практика глубокого слушания пространства.',
        parse_mode='HTML',
        reply_markup=InlineKeyboardMarkup(buttons),
    )
    return HOME


async def on_home(update: Update, context: ContextTypes.DEFAULT_TYPE) -> int:
    text = update.message.text
    if text == '📖 Открыть дневник':
        url = await journal_url(update.effective_user.id, update.effective_user.full_name)
        await update.message.reply_text(
            'Открывайте дневник:',
            reply_markup=InlineKeyboardMarkup([[
                InlineKeyboardButton('Открыть дневник 📖', web_app=WebAppInfo(url=url))
            ]]),
        )
        return HOME
    # "🎧 Начать практику"
    await update.message.reply_text(
        '🎧 <b>Слушание пространства</b>\n\n'
        'Займите удобное положение, сделайте три глубоких вдоха и закройте глаза.\n'
        'Слушайте пространство без попытки оценить происходящее.\n\n'
        'Выберите длительность практики:',
        parse_mode='HTML',
        reply_markup=kb_durations(),
    )
    return CHOOSE_DURATION


async def on_choose_duration(update: Update, context: ContextTypes.DEFAULT_TYPE) -> int:
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
        reply_markup=kb_done(),
    )
    return WAITING_DONE


async def on_waiting_done(update: Update, context: ContextTypes.DEFAULT_TYPE) -> int:
    query = update.callback_query
    await query.answer()
    await context.bot.send_message(
        query.message.chat_id,
        '📍 <b>Где вы находились?</b>\n\n<i>Например: парк, квартира, залив, крыша…</i>',
        parse_mode='HTML',
        reply_markup=kb_skip(),
    )
    return ASK_PLACE


async def on_ask_place(update: Update, context: ContextTypes.DEFAULT_TYPE) -> int:
    if update.callback_query:
        await update.callback_query.answer()
        context.user_data['place'] = ''
    else:
        text = update.message.text
        context.user_data['place'] = '' if is_skip(text) else text
    await context.bot.send_message(
        update.effective_chat.id,
        '🔊 Звуки <b>⬆️ СПЕРЕДИ</b>\n\n<i>Перечислите через запятую или нажмите «Пропустить»</i>',
        parse_mode='HTML',
        reply_markup=kb_skip(),
    )
    return ASK_FRONT


async def on_ask_front(update: Update, context: ContextTypes.DEFAULT_TYPE) -> int:
    if update.callback_query:
        await update.callback_query.answer()
        context.user_data['front'] = ''
    else:
        text = update.message.text
        context.user_data['front'] = '' if is_skip(text) else text
    await context.bot.send_message(
        update.effective_chat.id,
        '🔊 Звуки <b>➡️ СПРАВА</b>\n\n<i>Перечислите через запятую или нажмите «Пропустить»</i>',
        parse_mode='HTML',
        reply_markup=kb_skip(),
    )
    return ASK_RIGHT


async def on_ask_right(update: Update, context: ContextTypes.DEFAULT_TYPE) -> int:
    if update.callback_query:
        await update.callback_query.answer()
        context.user_data['right'] = ''
    else:
        text = update.message.text
        context.user_data['right'] = '' if is_skip(text) else text
    await context.bot.send_message(
        update.effective_chat.id,
        '🔊 Звуки <b>⬇️ СЗАДИ</b>\n\n<i>Перечислите через запятую или нажмите «Пропустить»</i>',
        parse_mode='HTML',
        reply_markup=kb_skip(),
    )
    return ASK_BACK


async def on_ask_back(update: Update, context: ContextTypes.DEFAULT_TYPE) -> int:
    if update.callback_query:
        await update.callback_query.answer()
        context.user_data['back'] = ''
    else:
        text = update.message.text
        context.user_data['back'] = '' if is_skip(text) else text
    await context.bot.send_message(
        update.effective_chat.id,
        '🔊 Звуки <b>⬅️ СЛЕВА</b>\n\n<i>Перечислите через запятую или нажмите «Пропустить»</i>',
        parse_mode='HTML',
        reply_markup=kb_skip(),
    )
    return ASK_LEFT


async def on_ask_left(update: Update, context: ContextTypes.DEFAULT_TYPE) -> int:
    if update.callback_query:
        await update.callback_query.answer()
        context.user_data['left'] = ''
    else:
        text = update.message.text
        context.user_data['left'] = '' if is_skip(text) else text
    await context.bot.send_message(
        update.effective_chat.id,
        '❤️ <b>Что понравилось или привлекло внимание?</b>\n\n<i>Звуки, качества, ощущения…</i>',
        parse_mode='HTML',
        reply_markup=kb_skip(),
    )
    return ASK_LIKED


async def on_ask_liked(update: Update, context: ContextTypes.DEFAULT_TYPE) -> int:
    if update.callback_query:
        await update.callback_query.answer()
        context.user_data['liked'] = ''
    else:
        text = update.message.text
        context.user_data['liked'] = '' if is_skip(text) else text
    await context.bot.send_message(
        update.effective_chat.id,
        '💔 <b>Что мешало или казалось лишним?</b>',
        parse_mode='HTML',
        reply_markup=kb_skip(),
    )
    return ASK_DISLIKED


async def on_ask_disliked(update: Update, context: ContextTypes.DEFAULT_TYPE) -> int:
    if update.callback_query:
        await update.callback_query.answer()
        context.user_data['disliked'] = ''
    else:
        text = update.message.text
        context.user_data['disliked'] = '' if is_skip(text) else text
    await context.bot.send_message(
        update.effective_chat.id,
        '📖 <b>Опишите историю, которую вы услышали.</b>\n\n'
        '<i>Какой образ, сюжет или настроение возникло из звуков?</i>',
        parse_mode='HTML',
        reply_markup=kb_skip(),
    )
    return ASK_STORY


async def on_ask_story(update: Update, context: ContextTypes.DEFAULT_TYPE) -> int:
    if update.callback_query:
        await update.callback_query.answer()
        context.user_data['story'] = ''
    else:
        text = update.message.text
        context.user_data['story'] = '' if is_skip(text) else text
    return await finish(update, context)


async def on_new_practice(update: Update, context: ContextTypes.DEFAULT_TYPE) -> int:
    query = update.callback_query
    await query.answer()
    await query.message.reply_text(
        '🎧 <b>Слушание пространства</b>\n\n'
        'Займите удобное положение, сделайте три глубоких вдоха и закройте глаза.\n'
        'Слушайте пространство без попытки оценить происходящее.\n\n'
        'Выберите длительность практики:',
        parse_mode='HTML',
        reply_markup=kb_durations(),
    )
    return CHOOSE_DURATION


# ── Main ──────────────────────────────────────────────────────────────────────

async def post_init(app: Application) -> None:
    await app.bot.set_my_description(
        description=(
            'Привет! Добро пожаловать в Звуковые Опыты. '
            'Это бот, который помогает вести дневник звуковых практик '
            'и развивать навык слухового восприятия. '
            'Жми /start чтобы начать.'
        ),
        language_code='ru',
    )

def main() -> None:
    app = Application.builder().token(BOT_TOKEN).post_init(post_init).build()

    conv = ConversationHandler(
        entry_points=[
            CommandHandler('start', cmd_start),
            MessageHandler(filters.Regex('^📖 Открыть дневник$'), on_home),
            MessageHandler(filters.Regex('^🎧 Начать практику$'), on_home),
            CallbackQueryHandler(on_new_practice, pattern='^new_practice$'),
        ],
        states={
            HOME: [
                CallbackQueryHandler(on_new_practice, pattern='^new_practice$'),
                MessageHandler(filters.TEXT & ~filters.COMMAND, on_home),
            ],
            CHOOSE_DURATION: [CallbackQueryHandler(on_choose_duration, pattern='^dur_')],
            WAITING_DONE:    [CallbackQueryHandler(on_waiting_done, pattern='^done$')],
            ASK_PLACE:       [
                MessageHandler(filters.TEXT & ~filters.COMMAND, on_ask_place),
                CallbackQueryHandler(on_ask_place, pattern='^skip$'),
            ],
            ASK_FRONT:       [
                MessageHandler(filters.TEXT & ~filters.COMMAND, on_ask_front),
                CallbackQueryHandler(on_ask_front, pattern='^skip$'),
            ],
            ASK_RIGHT:       [
                MessageHandler(filters.TEXT & ~filters.COMMAND, on_ask_right),
                CallbackQueryHandler(on_ask_right, pattern='^skip$'),
            ],
            ASK_BACK:        [
                MessageHandler(filters.TEXT & ~filters.COMMAND, on_ask_back),
                CallbackQueryHandler(on_ask_back, pattern='^skip$'),
            ],
            ASK_LEFT:        [
                MessageHandler(filters.TEXT & ~filters.COMMAND, on_ask_left),
                CallbackQueryHandler(on_ask_left, pattern='^skip$'),
            ],
            ASK_LIKED:       [
                MessageHandler(filters.TEXT & ~filters.COMMAND, on_ask_liked),
                CallbackQueryHandler(on_ask_liked, pattern='^skip$'),
            ],
            ASK_DISLIKED:    [
                MessageHandler(filters.TEXT & ~filters.COMMAND, on_ask_disliked),
                CallbackQueryHandler(on_ask_disliked, pattern='^skip$'),
            ],
            ASK_STORY:       [
                MessageHandler(filters.TEXT & ~filters.COMMAND, on_ask_story),
                CallbackQueryHandler(on_ask_story, pattern='^skip$'),
            ],
        },
        fallbacks=[CommandHandler('start', cmd_start)],
        allow_reentry=True,
    )
    app.add_handler(conv)

    app.run_webhook(
        listen='0.0.0.0',
        port=PORT,
        url_path='webhook',
        webhook_url=f'{WEBHOOK_URL}/webhook',
    )


if __name__ == '__main__':
    main()
